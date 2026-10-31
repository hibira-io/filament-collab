<?php

namespace Hibira\FilamentCollab\Tests;

use Hibira\Filament\HibiraFilamentServiceProvider;
use Hibira\FilamentCollab\CollabServiceProvider;
use Hibira\FilamentCollab\Realtime;
use Hibira\FilamentCollab\Tests\Fixtures\AdminPanelProvider;
use Hibira\FilamentCollab\Tests\Fixtures\FakeRealtime;
use Hibira\Laravel\HibiraServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected FakeRealtime $realtime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realtime = new FakeRealtime;
        $this->app->instance(Realtime::class, $this->realtime);
    }

    protected function getPackageProviders($app)
    {
        // Filament 3, 4 and 5 ship different packages; register the ones installed.
        $providers = array_filter([
            'BladeUI\\Heroicons\\BladeHeroiconsServiceProvider',
            'BladeUI\\Icons\\BladeIconsServiceProvider',
            'RyanChandler\\BladeCaptureDirective\\BladeCaptureDirectiveServiceProvider',
            'Livewire\\LivewireServiceProvider',
            'Filament\\Support\\SupportServiceProvider',
            'Filament\\Actions\\ActionsServiceProvider',
            'Filament\\Forms\\FormsServiceProvider',
            'Filament\\Infolists\\InfolistsServiceProvider',
            'Filament\\Notifications\\NotificationsServiceProvider',
            'Filament\\QueryBuilder\\QueryBuilderServiceProvider',
            'Filament\\Schemas\\SchemasServiceProvider',
            'Filament\\Tables\\TablesServiceProvider',
            'Filament\\Widgets\\WidgetsServiceProvider',
            'Filament\\FilamentServiceProvider',
            HibiraServiceProvider::class,
        ], 'class_exists');

        return array_merge(array_values($providers), [
            HibiraFilamentServiceProvider::class,
            CollabServiceProvider::class,
            AdminPanelProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://shop.example');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('broadcasting.default', 'hibira');
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
        $app['config']->set('hibira.app_id', '1001');
        $app['config']->set('hibira.key', 'pk_test');
        $app['config']->set('hibira.secret', 'sk_test');
    }

    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->timestamps();
        });

        (require __DIR__.'/../database/migrations/2026_10_31_100000_create_hibira_collab_tables.php')->up();
    }
}
