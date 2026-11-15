<?php

declare(strict_types=1);

namespace FancyChat\Tests\Support;

use FancyChat\FancyChatServiceProvider;
use Illuminate\Broadcasting\BroadcastServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\NullHandler;

/**
 * A hand-built Laravel 13 kernel, the framework this package integrates with,
 * booted directly with no orchestra/testbench (as fancy-cowork-php does).
 *
 * In-memory SQLite, FORCED: the database config is written here and nothing
 * from the environment replaces it, so a run under Genie's Postgres env never
 * touches a real database. Provider keys are blanked. The queue is `sync`, so a
 * turn job runs inside the request that dispatched it. The `capture`
 * broadcaster records every broadcast instead of sending it.
 */
final class TestApp
{
    /** @param  array<string, mixed>  $config  dotted keys merged over the defaults */
    public static function boot(array $config = [], ?string $sqlitePath = null): Application
    {
        foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'GROQ_API_KEY', 'XAI_API_KEY', 'DEEPSEEK_API_KEY', 'OPENROUTER_API_KEY'] as $key) {
            putenv($key.'=');
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-chat-tests';
        foreach (['/config', '/storage'] as $dir) {
            if (! is_dir($base.$dir)) {
                mkdir($base.$dir, 0777, true);
            }
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        $app['env'] = 'testing';
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
                'driver' => 'sqlite',
                'database' => $sqlitePath ?? ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 10000,
                'journal_mode' => $sqlitePath === null ? null : 'wal',
            ]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'broadcasting' => ['default' => 'capture', 'connections' => [
                'capture' => ['driver' => 'capture'],
                'null' => ['driver' => 'null'],
            ]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([EventServiceProvider::class, FilesystemServiceProvider::class, DatabaseServiceProvider::class,
            BusServiceProvider::class, QueueServiceProvider::class, BroadcastServiceProvider::class, RoutingServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        CaptureBroadcaster::$sent = [];
        $app->make('Illuminate\Broadcasting\BroadcastManager')->extend('capture', fn () => new CaptureBroadcaster);
        $app['router']->middlewareGroup('web', []);
        $app->register(FancyChatServiceProvider::class);

        if ($sqlitePath === null || ! $app['db']->connection()->getSchemaBuilder()->hasTable('fancy_chat_events')) {
            self::migrate(dirname(__DIR__, 2).'/database/migrations');
        }
        $app->boot();

        return $app;
    }

    private static function migrate(string $directory): void
    {
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }
    }
}
