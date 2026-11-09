<?php

namespace Nine\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Nine\Discovery;
use Nine\Http\HttpClientInterface;
use Nine\Laravel\NineServiceProvider;
use Nine\TokenVerifier;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var FakeTenant */
    protected $tenant;

    protected function setUp(): void
    {
        Discovery::forget();
        TokenVerifier::forgetKeys();
        parent::setUp();
    }

    protected function getPackageProviders($app)
    {
        return [NineServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('nine.issuer', FakeTenant::ISSUER);
        $app['config']->set('nine.client_id', 'app_web');
        $app['config']->set('nine.client_secret', 'web_secret');
        $app['config']->set('nine.api.audience', 'https://api.acme.test');
        $app['config']->set('nine.webhook.secret', 'whsec_test');
        $app['config']->set('cache.default', 'array');

        $this->tenant = new FakeTenant();
        $app->instance(HttpClientInterface::class, $this->tenant);
    }

    protected function defineRoutes($router)
    {
        Route::middleware(['web', 'nine:redirect'])->get('/dashboard', function () {
            return 'hello ' . auth()->user()->email;
        });
        Route::middleware('nine')->get('/api/me', function () {
            return response()->json(['sub' => auth()->id(), 'scope' => auth()->user()->scope]);
        });
        Route::middleware(['nine', 'nine.scope:orders:write'])->post('/api/orders', function () {
            return response()->json(['ok' => true]);
        });
        Route::middleware(['web', 'nine:passive'])->get('/maybe', function () {
            return auth('nine')->check() ? 'in' : 'out';
        });
    }
}
