<?php

namespace Nine\Laravel;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Nine\Http\CurlHttpClient;
use Nine\Http\HttpClientInterface;
use Nine\Laravel\Http\Middleware\Authenticate;
use Nine\Laravel\Http\Middleware\RequireScopes;
use Nine\Laravel\Http\Middleware\VerifyWebhookSignature;
use Nine\ManagementClient;
use Nine\TokenVerifier;

class NineServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/nine.php', 'nine');

        $this->app->bindIf(HttpClientInterface::class, CurlHttpClient::class);

        $this->app->singleton(NineManager::class, function ($app) {
            return new NineManager(
                $app['config'],
                $app->make(HttpClientInterface::class),
                new LaravelCache($app['cache']->store($app['config']->get('nine.cache.store')))
            );
        });
        $this->app->alias(NineManager::class, 'nine');

        $this->app->bind(TokenVerifier::class, function ($app) {
            return $app->make(NineManager::class)->verifier();
        });
        $this->app->bind(\Nine\HybridTokenVerifier::class, function ($app) {
            return $app->make(NineManager::class)->tokenVerifier();
        });
        $this->app->bind(ManagementClient::class, function ($app) {
            return $app->make(NineManager::class)->management();
        });
    }

    public function boot()
    {
        // `auth('nine')` works without editing config/auth.php.
        if (!$this->app['config']->has('auth.guards.nine')) {
            $this->app['config']->set('auth.guards.nine', ['driver' => 'nine']);
        }

        Auth::extend('nine', function ($app, $name, array $config) {
            $guard = new NineGuard(
                $app->make(NineManager::class),
                $app['request'],
                isset($config['provider']) ? Auth::createUserProvider($config['provider']) : null,
                $config['local_key'] ?? 'nine_id'
            );
            $app->refresh('request', $guard, 'setRequest');
            return $guard;
        });

        $router = $this->app['router'];
        $router->aliasMiddleware('nine', Authenticate::class);
        $router->aliasMiddleware('nine.scope', RequireScopes::class);
        $router->aliasMiddleware('nine.webhook', VerifyWebhookSignature::class);

        if ($this->app['config']->get('nine.routes.enabled', true) && !$this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/nine.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/nine.php' => config_path('nine.php'),
            ], ['nine', 'nine:config']);
        }
    }
}
