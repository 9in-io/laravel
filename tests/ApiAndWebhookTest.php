<?php

namespace Nine\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use Nine\Laravel\Events\EventReceived;
use Nine\Laravel\Events\UserCreated;
use Nine\Laravel\Facades\Nine;
use Nine\Signature;

class ApiAndWebhookTest extends TestCase
{
    public function testBearerTokensAreVerifiedLocally(): void
    {
        $token = $this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'orders:read']);
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertExactJson(['sub' => 'usr_1', 'scope' => 'orders:read']);

        // keys are fetched once
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertCount(1, $this->tenant->callsTo(FakeTenant::ISSUER . '/.well-known/jwks.json'));
    }

    public function testInvalidTokensAre401(): void
    {
        $this->getJson('/api/me')->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer realm="9in"');
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $this->tenant->token(['aud' => 'https://other.api'])])->assertStatus(401);
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $this->tenant->token(['exp' => time() - 600])])->assertStatus(401);
        $foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $this->tenant->token([], $foreignKey)])->assertStatus(401);
    }

    public function testScopes(): void
    {
        $reader = $this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'orders:read']);
        $this->postJson('/api/orders', [], ['Authorization' => "Bearer {$reader}"])->assertStatus(403)->assertJson(['error' => 'insufficient_scope']);

        $writer = $this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'orders:read orders:write']);
        $this->postJson('/api/orders', [], ['Authorization' => "Bearer {$writer}"])->assertOk();
    }

    public function testFacade(): void
    {
        $claims = Nine::verifyAccessToken($this->tenant->token(['aud' => 'https://api.acme.test']));
        $this->assertSame('usr_1', $claims['sub']);
    }

    private function webhook(string $body, ?string $secret = 'whsec_test', ?int $timestamp = null)
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($secret) {
            $headers['HTTP_9IN_SIGNATURE'] = Signature::sign($secret, 'POST', '/nine/webhook', $body, $timestamp);
        }
        return $this->call('POST', '/nine/webhook', [], [], [], $headers, $body);
    }

    public function testVerifiedWebhooksAreDispatched(): void
    {
        Event::fake([UserCreated::class, EventReceived::class]);
        $body = json_encode(['id' => 'evt_1', 'event' => 'user.created', 'created_at' => '2026-11-09T10:00:00.000Z', 'tenant' => 'tnt_1', 'environment' => 'production', 'data' => ['user' => ['id' => 'usr_9', 'email' => 'new@acme.com']]]);

        $this->webhook($body)->assertStatus(204);

        Event::assertDispatched(UserCreated::class, function (UserCreated $event) {
            return $event->fromWebhook() && $event->user()['email'] === 'new@acme.com' && $event->id() === 'evt_1' && $event->userId() === 'usr_9';
        });
        Event::assertDispatched(EventReceived::class);
    }

    public function testRetriesOfAProcessedEventAreNotDispatchedAgain(): void
    {
        $count = 0;
        Event::listen(UserCreated::class, function () use (&$count) {
            $count++;
        });
        $body = json_encode(['id' => 'evt_dup', 'event' => 'user.created', 'created_at' => '2026-11-09T10:00:00.000Z', 'data' => ['user' => ['id' => 'usr_9']]]);

        $this->webhook($body)->assertStatus(204);
        $this->webhook($body)->assertStatus(204);
        $this->assertSame(1, $count);
    }

    public function testFailedListenersAreRetried(): void
    {
        $this->withoutExceptionHandling();
        $attempts = 0;
        Event::listen(UserCreated::class, function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('db down');
            }
        });
        $body = json_encode(['id' => 'evt_retry', 'event' => 'user.created', 'created_at' => '2026-11-09T10:00:00.000Z', 'data' => []]);

        try {
            $this->webhook($body);
            $this->fail('expected the listener exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }
        $this->webhook($body)->assertStatus(204);
        $this->assertSame(2, $attempts);
    }

    public function testConcurrentDeliveriesGet409(): void
    {
        $this->app->make(\Nine\Laravel\NineManager::class)->cache()->add('nine:webhook:evt_busy', 'processing', 300);
        $body = json_encode(['id' => 'evt_busy', 'event' => 'user.created', 'created_at' => '2026-11-09T10:00:00.000Z', 'data' => []]);
        $this->webhook($body)->assertStatus(409)->assertExactJson(['error' => 'in_progress']);
    }

    public function testDedupeCanBeTurnedOff(): void
    {
        config(['nine.webhook.dedupe' => false]);
        $count = 0;
        Event::listen(UserCreated::class, function () use (&$count) {
            $count++;
        });
        $body = json_encode(['id' => 'evt_twice', 'event' => 'user.created', 'created_at' => '2026-11-09T10:00:00.000Z', 'data' => []]);
        $this->webhook($body)->assertStatus(204);
        $this->webhook($body)->assertStatus(204);
        $this->assertSame(2, $count);
    }

    public function testInsecureIssuersAreRefused(): void
    {
        config(['nine.issuer' => 'http://acme.9in.test']);
        $this->app->forgetInstance(\Nine\Laravel\NineManager::class);
        $this->get('/nine/login')->assertStatus(500);

        config(['nine.allow_insecure_issuer' => true]);
        $this->app->forgetInstance(\Nine\Laravel\NineManager::class);
        $this->assertSame('http://acme.9in.test', \Nine\Laravel\Facades\Nine::client()->config()->issuer);
    }

    public function testUnsignedOrStaleWebhooksAreRejected(): void
    {
        Event::fake([UserCreated::class, EventReceived::class]);
        $body = json_encode(['id' => 'evt_1', 'event' => 'user.created', 'data' => []]);
        $this->webhook($body, null)->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);
        $this->webhook($body, 'other-secret')->assertStatus(401);
        $this->webhook($body, 'whsec_test', time() - 3600)->assertStatus(401);
        Event::assertNotDispatched(UserCreated::class);
        Event::assertNotDispatched(EventReceived::class);
    }

    public function testLegacyEnvironmentFallback(): void
    {
        putenv('SSOFY_URL=https://login.legacy.test');
        putenv('SSOFY_CLIENT_ID=legacy_app');
        try {
            $config = require __DIR__ . '/../config/nine.php';
            $this->assertSame('https://login.legacy.test', $config['issuer']);
            $this->assertSame('legacy_app', $config['client_id']);
        } finally {
            putenv('SSOFY_URL');
            putenv('SSOFY_CLIENT_ID');
        }
    }
}
