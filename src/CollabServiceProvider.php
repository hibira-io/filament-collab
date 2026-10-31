<?php

namespace Hibira\FilamentCollab;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class CollabServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hibira-collab.php', 'hibira-collab');
        $this->app->singleton(Realtime::class);
        $this->app->singleton(Collab::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hibira-collab');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'hibira-collab');

        FilamentAsset::register([
            AlpineComponent::make('hibira-collab-editor', __DIR__.'/../resources/dist/hibira-collab.js'),
            Css::make('hibira-collab', __DIR__.'/../resources/dist/hibira-collab.css'),
        ], 'hibira-io/filament-collab');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/hibira-collab.php' => config_path('hibira-collab.php'),
            ], 'hibira-collab-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/hibira-collab'),
            ], 'hibira-collab-translations');
        }
    }
}
