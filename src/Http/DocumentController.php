<?php

namespace Hibira\FilamentCollab\Http;

use Filament\Facades\Filament;
use Hibira\FilamentCollab\Collab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Loads documents for editors and takes their changes. Mounted in each
 * panel that has the plugin, behind the panel's own authentication.
 */
class DocumentController
{
    public function __construct(protected Collab $collab) {}

    public function show(Request $request, string $document): JsonResponse
    {
        $this->authorize($request, $document);

        $data = $request->validate([
            'source' => ['nullable', 'string', 'size:64'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($this->collab->open(
            $document,
            source: $data['source'] ?? null,
            after: (int) ($data['after'] ?? 0),
        ));
    }

    public function seed(Request $request, string $document): JsonResponse
    {
        $this->authorize($request, $document, edit: true);
        $data = $this->validateUpdate($request);

        $id = $this->collab->seed($document, $data['generation'], $data['update'], $this->author($request));

        return $id === null
            ? response()->json(['message' => 'Already started.'], 409)
            : response()->json(['id' => $id]);
    }

    public function update(Request $request, string $document): JsonResponse
    {
        $this->authorize($request, $document, edit: true);
        $data = $this->validateUpdate($request);

        $socket = $request->header('X-Socket-ID');
        $socket = is_string($socket) && preg_match('/\A\d+\.\d+\z/', $socket) ? $socket : null;

        $result = $this->collab->append($document, $data['generation'], $data['update'], $this->author($request), $socket);

        return $result === null
            ? response()->json(['message' => 'The document was reloaded.'], 409)
            : response()->json($result);
    }

    public function compact(Request $request, string $document): JsonResponse
    {
        $this->authorize($request, $document, edit: true);
        $data = $this->validateUpdate($request) + $request->validate([
            'upto' => ['required', 'integer', 'min:1'],
        ]);

        return $this->collab->compact($document, $data['generation'], (int) $data['upto'], $data['update'])
            ? response()->json(['ok' => true])
            : response()->json(['message' => 'The document was reloaded.'], 409);
    }

    protected function authorize(Request $request, string $document, bool $edit = false): void
    {
        $grant = $this->collab->grantFor(Filament::getCurrentPanel()?->getId() ?? '', $document, Filament::auth()->user());

        abort_if($grant === null || ($edit && ! $grant['edit']), 403);
    }

    /** @return array{generation: int, update: string} */
    protected function validateUpdate(Request $request): array
    {
        $max = (int) ceil(max(1024, (int) config('hibira-collab.max_update_bytes', 1048576)) * 4 / 3) + 4;

        $data = $request->validate([
            'generation' => ['required', 'integer', 'min:1'],
            'update' => ['required', 'string', 'max:'.$max, 'regex:/\A[A-Za-z0-9+\/]+={0,2}\z/'],
        ]);

        return ['generation' => (int) $data['generation'], 'update' => $data['update']];
    }

    protected function author(Request $request): ?string
    {
        $id = Filament::auth()->id();

        return $id === null ? null : (string) $id;
    }
}
