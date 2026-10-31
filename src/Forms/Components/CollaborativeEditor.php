<?php

namespace Hibira\FilamentCollab\Forms\Components;

use Closure;
use Composer\InstalledVersions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;
use Hibira\FilamentCollab\Collab;
use Hibira\FilamentCollab\HibiraCollabPlugin;
use Illuminate\Database\Eloquent\Model;

/**
 * A rich text editor that several people can type in at once, with each
 * other's cursors, on the edit page of a record.
 *
 * The field's state is HTML, like Filament's RichEditor. On a create page,
 * or in a panel without HibiraCollabPlugin, it is a plain editor.
 */
class CollaborativeEditor extends Field
{
    use HasPlaceholder;

    protected string $view = 'hibira-collab::forms.collaborative-editor';

    protected bool | Closure $isCollaborative = true;

    protected string | Closure | null $documentKey = null;

    protected string | Closure | null $minHeight = '12rem';

    /** @var list<string> | Closure */
    protected array | Closure $toolbarButtons = [
        'bold', 'italic', 'strike', 'code', 'link',
        'h2', 'h3',
        'bulletList', 'orderedList', 'blockquote', 'codeBlock',
        'undo', 'redo',
    ];

    /** @var list<string> */
    protected array $disabledToolbarButtons = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->default('');

        // Runs after the record is saved: the stored document matches the
        // saved value again, so it is not reset next time.
        $this->saveRelationshipsUsing(function (CollaborativeEditor $component, $state) {
            $key = $component->getDocumentKey();

            if ($key !== null) {
                app(Collab::class)->saved($key, $state);
            }
        });
    }

    /** Turns live collaboration on or off. On by default. */
    public function collaborative(bool | Closure $condition = true): static
    {
        $this->isCollaborative = $condition;

        return $this;
    }

    /**
     * Names the shared document yourself, to share it between fields or
     * records. By default it is the record's model, key and the field.
     */
    public function documentKey(string | Closure | null $key): static
    {
        $this->documentKey = $key;

        return $this;
    }

    public function minHeight(string | Closure | null $height): static
    {
        $this->minHeight = $height;

        return $this;
    }

    /** @param  list<string> | Closure  $buttons */
    public function toolbarButtons(array | Closure $buttons): static
    {
        $this->toolbarButtons = $buttons;

        return $this;
    }

    /** @param  list<string>  $buttons */
    public function disableToolbarButtons(array $buttons): static
    {
        $this->disabledToolbarButtons = [...$this->disabledToolbarButtons, ...$buttons];

        return $this;
    }

    /** @return list<string> */
    public function getToolbarButtons(): array
    {
        return array_values(array_diff((array) $this->evaluate($this->toolbarButtons), $this->disabledToolbarButtons));
    }

    public function getMinHeight(): ?string
    {
        return $this->evaluate($this->minHeight);
    }

    public function isCollaborative(): bool
    {
        return (bool) $this->evaluate($this->isCollaborative);
    }

    public function getDocumentKey(): ?string
    {
        $subject = $this->getDocumentSubject();

        return $subject === null ? null : Collab::key($subject);
    }

    public function getDocumentSubject(): ?string
    {
        if ($this->documentKey !== null) {
            $key = $this->evaluate($this->documentKey);

            return blank($key) ? null : 'custom:'.$key;
        }

        $record = $this->getRecord();

        if (! $record instanceof Model || ! $record->exists) {
            return null;
        }

        return $record->getMorphClass().':'.$record->getKey().':'.$this->getStatePath();
    }

    /**
     * What the browser needs to join the shared document, or null for a
     * plain editor. Also lets the current user open the document.
     *
     * @return array<string, mixed>|null
     */
    public function getCollabConfig(): ?array
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();
        $key = $this->getDocumentKey();

        if (
            ! $this->isCollaborative()
            || $panel === null
            || $user === null
            || $key === null
            || ! HibiraCollabPlugin::has($panel->getId())
            || blank(config('hibira.key'))
        ) {
            return null;
        }

        $canEdit = ! $this->isDisabled();
        app(Collab::class)->grant($panel->getId(), $key, $user, $canEdit);

        $route = fn (string $name) => route("filament.{$panel->getId()}.hibira-collab.{$name}", ['document' => $key], absolute: false);

        return [
            'channel' => Collab::channel($panel->getId(), $key),
            'source' => Collab::hash($this->getState() ?? ''),
            'readOnly' => ! $canEdit,
            'urls' => [
                'show' => $route('show'),
                'seed' => $route('seed'),
                'update' => $route('update'),
                'compact' => $route('compact'),
            ],
            'user' => [
                'id' => (string) $user->getAuthIdentifier(),
                'name' => Filament::getUserName($user),
                'color' => HibiraCollabPlugin::color($user),
            ],
        ];
    }

    /** Filament 3 loads Alpine components with ax-load, later versions with x-load. */
    public static function usesAsyncAlpine(): bool
    {
        return str_starts_with((string) InstalledVersions::getVersion('filament/support'), '3.');
    }
}
