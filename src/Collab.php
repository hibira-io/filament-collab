<?php

namespace Hibira\FilamentCollab;

use Hibira\FilamentCollab\Models\Document;
use Hibira\FilamentCollab\Models\Update;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Stores and relays the shared documents behind collaborative editors.
 *
 * The server never parses Yjs: it keeps each document as a list of updates
 * (base64), hands them to editors that open it, and passes new ones on to
 * the editors on the document's presence channel. Every update can be
 * applied more than once and in any order, which keeps this simple.
 *
 * A document has a generation. When the saved value of the field is changed
 * outside the editor (an import, an API call), the next editor that opens
 * it starts a new generation from that value, and editors still on the old
 * one reload.
 */
class Collab
{
    public function __construct(protected Realtime $realtime) {}

    /** The document key for a field of a record. */
    public static function key(string $subject): string
    {
        return substr(hash('sha256', $subject), 0, 40);
    }

    /** The fingerprint of a saved field value. */
    public static function hash(mixed $state): string
    {
        return hash('sha256', is_string($state) ? $state : (string) json_encode($state));
    }

    /** Laravel channel name of a document, or with its presence- prefix for Hibira. */
    public static function channel(string $panelId, string $key, bool $prefixed = false): string
    {
        return ($prefixed ? 'presence-' : '')."hibira-collab.{$panelId}.{$key}";
    }

    /**
     * Lets a user open a document from a panel for a while. Given when the
     * page with the editor is rendered, which Filament has already
     * authorized.
     */
    public function grant(string $panelId, string $key, Authenticatable $user, bool $canEdit): void
    {
        Cache::put(
            $this->grantKey($panelId, $key, $user),
            ['edit' => $canEdit],
            now()->addMinutes(max(1, (int) config('hibira-collab.grant_minutes', 720))),
        );
    }

    /** @return array{edit: bool}|null */
    public function grantFor(string $panelId, string $key, ?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $grant = Cache::get($this->grantKey($panelId, $key, $user));

        return is_array($grant) ? $grant : null;
    }

    /**
     * The document's updates after the given id. With a source hash, the
     * document starts again from that value when it no longer matches.
     *
     * @return array{generation: int, seeded: bool, updates: list<array{id: int, u: string}>}
     */
    public function open(string $key, ?string $source = null, int $after = 0, ?string $subject = null): array
    {
        $document = $this->document($key, $subject);

        if ($source !== null) {
            if (! $document->seeded) {
                $document->forceFill(['source_hash' => $source])->save();
            } elseif ($document->source_hash !== null && ! hash_equals($document->source_hash, $source)) {
                $this->reset($document, $source);
            }
        }

        $updates = Update::query()
            ->where('document_id', $document->id)
            ->where('generation', $document->generation)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->get(['id', 'update'])
            ->map(fn (Update $update) => ['id' => $update->id, 'u' => $update->update])
            ->all();

        return [
            'generation' => $document->generation,
            'seeded' => $document->seeded,
            'updates' => $updates,
        ];
    }

    /**
     * Fills an empty document with the field's saved value. Null when another
     * editor got there first.
     */
    public function seed(string $key, int $generation, string $update, ?string $author = null): ?int
    {
        return $this->locked($key, function (Document $document) use ($generation, $update, $author) {
            if ($document->seeded || $document->generation !== $generation) {
                return null;
            }

            $id = $this->store($document, $update, $author);
            $document->forceFill(['seeded' => true, 'pending_updates' => 0])->save();

            return $id;
        });
    }

    /**
     * Stores a change and passes it on to the other editors. Null when the
     * editor is on an old generation and must reload.
     *
     * @return array{id: int, compact: bool}|null
     */
    public function append(string $key, int $generation, string $update, ?string $author = null, ?string $exceptSocket = null): ?array
    {
        $result = $this->locked($key, function (Document $document) use ($generation, $update, $author) {
            if (! $document->seeded || $document->generation !== $generation) {
                return null;
            }

            $id = $this->store($document, $update, $author);
            $document->increment('pending_updates');

            return [
                'id' => $id,
                'compact' => $document->pending_updates >= max(10, (int) config('hibira-collab.compact_after', 200)),
            ];
        });

        if ($result === null) {
            return null;
        }

        $event = ['id' => $result['id'], 'g' => $generation, 'u' => $update];

        if (strlen((string) json_encode($event)) > (int) config('hibira-collab.max_event_bytes', 3500)) {
            // Too big for one event on the Free plan: editors fetch it.
            unset($event['u']);
        }

        $this->realtime->trigger($this->channels($key), 'update', $event, $exceptSocket);

        return $result;
    }

    /**
     * Replaces the updates up to an id with one snapshot of the whole
     * document, which an editor that has all of them sends.
     */
    public function compact(string $key, int $generation, int $upto, string $snapshot): bool
    {
        return $this->locked($key, function (Document $document) use ($generation, $upto, $snapshot) {
            if (! $document->seeded || $document->generation !== $generation) {
                return false;
            }

            Update::query()
                ->where('document_id', $document->id)
                ->where('id', '<=', $upto)
                ->delete();

            $this->store($document, $snapshot, null);
            $document->forceFill([
                'pending_updates' => Update::query()->where('document_id', $document->id)->count() - 1,
            ])->save();

            return true;
        });
    }

    /** Records that the field was saved with this value, so it is not reset. */
    public function saved(string $key, mixed $state): void
    {
        Document::query()->where('key', $key)->update(['source_hash' => static::hash($state)]);
    }

    /** @return list<string> the document's presence channel in every panel with the plugin */
    public function channels(string $key): array
    {
        return array_map(
            fn (string $panelId) => static::channel($panelId, $key, prefixed: true),
            HibiraCollabPlugin::panels(),
        );
    }

    protected function reset(Document $document, string $source): void
    {
        $this->locked($document->key, function (Document $document) use ($source) {
            Update::query()->where('document_id', $document->id)->delete();
            $document->forceFill([
                'generation' => $document->generation + 1,
                'seeded' => false,
                'source_hash' => $source,
                'pending_updates' => 0,
            ])->save();
        });

        $document->refresh();
        $this->realtime->trigger($this->channels($document->key), 'reset', ['g' => $document->generation]);
    }

    protected function store(Document $document, string $update, ?string $author): int
    {
        return Update::query()->create([
            'document_id' => $document->id,
            'generation' => $document->generation,
            'update' => $update,
            'author' => $author,
            'created_at' => now(),
        ])->id;
    }

    protected function document(string $key, ?string $subject = null): Document
    {
        $document = Document::query()->where('key', $key)->first();

        if ($document) {
            return $document;
        }

        try {
            return Document::query()->create([
                'key' => $key,
                'subject' => $subject,
                'generation' => 1,
                'seeded' => false,
                'pending_updates' => 0,
            ]);
        } catch (QueryException) {
            // Opened by someone else at the same moment.
            return Document::query()->where('key', $key)->firstOrFail();
        }
    }

    /**
     * @template T
     *
     * @param  callable(Document): T  $callback
     * @return T
     */
    protected function locked(string $key, callable $callback): mixed
    {
        $this->document($key);

        return Document::query()->getConnection()->transaction(function () use ($key, $callback) {
            $document = Document::query()->where('key', $key)->lockForUpdate()->firstOrFail();

            return $callback($document);
        });
    }

    protected function grantKey(string $panelId, string $key, Authenticatable $user): string
    {
        return 'hibira-collab:grant:'.$panelId.':'.$key.':'.$user->getAuthIdentifier();
    }
}
