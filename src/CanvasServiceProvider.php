<?php

namespace JarredCain\CanvasLms;

use Illuminate\Support\ServiceProvider;
use JarredCain\CanvasLms\Adapters\AdapterService;
use JarredCain\CanvasLms\Auth\AuthManager;
use JarredCain\CanvasLms\Auth\OAuth2\OAuth2Handler;
use JarredCain\CanvasLms\Auth\OAuth2\OAuthController;
use JarredCain\CanvasLms\Auth\Storage\CacheTokenStorage;
use JarredCain\CanvasLms\Auth\Storage\DatabaseTokenStorage;
use JarredCain\CanvasLms\Auth\Storage\TokenStorageInterface;
use JarredCain\CanvasLms\Console\Commands\TestAuthCommand;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Http\Controllers\AdapterController;

class CanvasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/canvas.php',
            'canvas'
        );

        $this->app->singleton(TokenStorageInterface::class, function ($app) {
            $config        = $app['config']['canvas'];
            $storageDriver = $config['oauth2']['storage_driver'] ?? 'cache';

            return match ($storageDriver) {
                'database' => new DatabaseTokenStorage(),
                default    => new CacheTokenStorage($config['oauth2']['cache_prefix'] ?? 'canvas_oauth_token'),
            };
        });

        $this->app->singleton(OAuth2Handler::class, function ($app) {
            return new OAuth2Handler(
                $app->make(TokenStorageInterface::class),
                $app['config']['canvas']
            );
        });

        $this->app->singleton(AuthManager::class, function ($app) {
            $config = $app['config']['canvas'];

            $oauth2Handler = $config['auth_driver'] === 'oauth2'
                ? $app->make(OAuth2Handler::class)
                : null;

            return new AuthManager($config, $oauth2Handler);
        });

        $this->app->singleton(CanvasClient::class, function ($app) {
            $config = $app['config']['canvas'];
            $auth   = $app->make(AuthManager::class);

            return new CanvasClient(
                $config['base_url'],
                $auth->getToken()
            );
        });

        $this->app->singleton('canvas', function ($app) {
            $config = $app['config']['canvas'];

            $oauth2Handler = $config['auth_driver'] === 'oauth2'
                ? $app->make(OAuth2Handler::class)
                : null;

            return new Canvas(
                $app->make(CanvasClient::class),
                $app->make(AuthManager::class),
                $oauth2Handler
            );
        });

        $this->app->singleton(AdapterService::class, function ($app) {
            return new AdapterService($app->make('canvas'));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestAuthCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/canvas.php' => config_path('canvas.php'),
            ], 'canvas-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/create_canvas_oauth_tokens_table.php'
                    => database_path('migrations/' . date('Y_m_d_His') . '_create_canvas_oauth_tokens_table.php'),
            ], 'canvas-migrations');

            $this->publishes([
                __DIR__ . '/Http/Controllers/AdapterController.php'
                    => app_path('Http/Controllers/Canvas/AdapterController.php'),
            ], 'canvas-adapter');
        }

        $this->registerOAuthRoutes();
        $this->registerAdapterRoutes();
    }

    private function registerAdapterRoutes(): void
    {
        if (!config('canvas.adapters.routes_enabled', false)) {
            return;
        }

        $this->app['router']->group([
            'prefix'     => 'canvas/adapter',
            'middleware' => ['api'],
        ], function ($router) {
            $router->post('{resource}/{id}', [AdapterController::class, 'mutate'])
                ->name('canvas.adapter.mutate');
        });
    }

    private function registerOAuthRoutes(): void
    {
        if (!config('canvas.oauth2.client_id')) {
            return;
        }

        $this->app['router']->group([
            'prefix'     => 'canvas/oauth',
            'middleware' => ['web'],
        ], function ($router) {
            $router->get('redirect', [OAuthController::class, 'redirect'])
                ->name('canvas.oauth.redirect');

            $router->get('callback', [OAuthController::class, 'callback'])
                ->name('canvas.oauth.callback');
        });
    }
}
