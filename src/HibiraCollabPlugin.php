<?php

namespace Hibira\FilamentCollab;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Hibira\Filament\Presence;
use Hibira\FilamentCollab\Http\DocumentController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/**
 * Lets CollaborativeEditor fields in a panel edit together.
 *
 * Use it next to HibiraPlugin from hibira-io/filament, which connects the
 * panel to Hibira. Without it, the fields are ordinary rich text editors.
 */
class HibiraCollabPlugin implements Plugin
{
    /** @var array<string, true> panels that have the plugin */
    protected static array $panels = [];

    /** Cursor and avatar colors, picked per user. */
    public const COLORS = [
        '#e11d48', '#ea580c', '#ca8a04', '#16a34a', '#0d9488',
        '#0284c7', '#4f46e5', '#9333ea', '#c026d3', '#64748b',
    ];

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'hibira-collab';
    }

    public function register(Panel $panel): void
    {
        static::$panels[$panel->getId()] = true;

        $panel->authenticatedRoutes(function () {
            Route::prefix('hibira-collab/{document}')
                ->where(['document' => '[a-f0-9]{40}'])
                ->name('hibira-collab.')
                ->group(function () {
                    Route::get('/', [DocumentController::class, 'show'])->name('show');
                    Route::post('seed', [DocumentController::class, 'seed'])->name('seed');
                    Route::post('updates', [DocumentController::class, 'update'])->name('update');
                    Route::post('compact', [DocumentController::class, 'compact'])->name('compact');
                });
        });

        // Authorized on /broadcasting/auth, outside the panel, so registered
        // for every request. After boot, so the hibira broadcaster exists.
        app()->booted(function () use ($panel) {
            Broadcast::channel(
                Collab::channel($panel->getId(), '{document}'),
                function ($user, string $document) use ($panel) {
                    if (app(Collab::class)->grantFor($panel->getId(), $document, $user) === null) {
                        return false;
                    }

                    $member = Presence::member($panel->getId(), $user);

                    return $member === false ? false : $member + ['color' => static::color($user)];
                },
                $panel->getAuthGuard() ? ['guards' => [$panel->getAuthGuard()]] : [],
            );
        });
    }

    public function boot(Panel $panel): void {}

    public static function has(?string $panelId): bool
    {
        return $panelId !== null && isset(static::$panels[$panelId]);
    }

    /** @return list<string> */
    public static function panels(): array
    {
        return array_keys(static::$panels);
    }

    public static function color(Authenticatable $user): string
    {
        return static::COLORS[crc32((string) $user->getAuthIdentifier()) % count(static::COLORS)];
    }
}
