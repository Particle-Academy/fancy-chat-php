<?php

declare(strict_types=1);

namespace FancyChat;

use FancyChat\Console\CommitUndoCommand;
use FancyChat\Console\ReapCommand;
use FancyChat\Store\DatabaseThreadEventStore;
use FancyChat\Store\MemoryThreadEventStore;
use FancyChat\Store\ThreadEventStore;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the store, the manager, the routes, the migration and
 * `fancy-chat:reap`. It binds NO host resolver: until the host does, every
 * route refuses with `chat.unconfigured`.
 */
final class FancyChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/fancy-chat.php', 'fancy-chat');

        $this->app->singleton(ThreadEventStore::class, fn ($app): ThreadEventStore => $app['config']->get('fancy-chat.store') === 'memory'
            ? new MemoryThreadEventStore
            : new DatabaseThreadEventStore($app['db'], $app['config']->get('fancy-chat.connection')));
        $this->app->singleton(FancyChat::class, fn ($app): FancyChat => new FancyChat($app, $app->make(ThreadEventStore::class), $app['config']));
    }

    public function boot(): void
    {
        $config = $this->app['config'];
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($config->get('fancy-chat.routes', true)) {
            Route::prefix((string) $config->get('fancy-chat.prefix', 'fancy-chat'))
                ->middleware((array) $config->get('fancy-chat.middleware', ['web']))
                ->group(__DIR__.'/../routes/fancy-chat.php');
        }

        $this->commands([ReapCommand::class, CommitUndoCommand::class]);
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/fancy-chat.php' => $this->app->configPath('fancy-chat.php')], 'fancy-chat-config');
            $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'fancy-chat-migrations');
        }
    }
}
