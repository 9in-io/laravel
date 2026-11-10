<?php

namespace Nine\Laravel\Tests;

use Illuminate\Support\Facades\Auth;
use Nine\HybridTokenVerifier;
use Nine\Jwt\Jwt;
use Nine\Laravel\NineManager;
use Nine\Signature;

class TokenVerificationTest extends TestCase
{
    private function rebuild(array $config = []): void
    {
        config($config);
        $this->app->forgetInstance(NineManager::class);
        Auth::forgetGuards();
    }

    private function api(string $token)
    {
        return $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"]);
    }

    private function webhook(array $event)
    {
        $body = json_encode($event + ['id' => 'evt_' . bin2hex(random_bytes(4)), 'created_at' => '2026-11-09T10:00:00.000Z']);
        return $this->call('POST', '/nine/webhook', [], [], [], [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_9IN_SIGNATURE' => Signature::sign('whsec_test', 'POST', '/nine/webhook', $body),
        ], $body);
    }

    public function testHybridIsTheDefaultWithClientCredentials(): void
    {
        $this->assertSame(HybridTokenVerifier::MODE_HYBRID, app(NineManager::class)->tokenVerifier()->mode());
        $token = $this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'orders:read']);
        $this->api($token)->assertOk();
        $this->api($token)->assertOk();
        $this->assertSame([$token], $this->tenant->introspected);
    }

    public function testTokenRevokedWebhookTakesEffectAtOnce(): void
    {
        $revoked = [];
        $this->tenant->introspection = function ($token) use (&$revoked) {
            return ['active' => !in_array($token, $revoked, true)];
        };
        $token = $this->tenant->token(['aud' => 'https://api.acme.test', 'jti' => 'jti_live', 'scope' => 'orders:read']);
        $this->api($token)->assertOk();

        $revoked[] = $token;
        $this->api($token)->assertOk(); // still cached: 9in has not told us yet
        $this->webhook(['event_type' => 'token.revoked', 'data' => ['token_type' => 'access_token', 'jti' => 'jti_live', 'client_id' => 'app_web', 'user' => ['id' => 'usr_1']]])->assertStatus(204);
        $this->api($token)->assertStatus(401);
        $this->assertCount(2, $this->tenant->introspected);
    }

    public function testSessionAndUserWebhooksEvict(): void
    {
        $a = $this->tenant->token(['aud' => 'https://api.acme.test', 'sid' => 'sid_a', 'scope' => 'x']);
        $b = $this->tenant->token(['aud' => 'https://api.acme.test', 'sid' => 'sid_b', 'sub' => 'usr_2', 'scope' => 'x']);
        $this->api($a)->assertOk();
        $this->api($b)->assertOk();

        $this->webhook(['event_type' => 'session.revoked', 'data' => ['sid' => 'sid_a', 'user' => ['id' => 'usr_1']]])->assertStatus(204);
        $this->webhook(['event_type' => 'user.deleted', 'data' => ['user' => ['id' => 'usr_2']]])->assertStatus(204);
        $this->api($a)->assertOk();
        $this->api($b)->assertOk();
        $this->assertCount(4, $this->tenant->introspected);
    }

    public function testOpaqueTokens(): void
    {
        $this->tenant->introspection = function ($token) {
            return $token === '9in_at_live'
                ? ['active' => true, 'token_type' => 'access_token', 'iss' => FakeTenant::ISSUER, 'sub' => 'usr_9', 'aud' => 'https://api.acme.test', 'scope' => 'orders:read', 'exp' => time() + 300]
                : ['active' => false];
        };
        $this->api('9in_at_live')->assertOk()->assertJson(['sub' => 'usr_9']);
        $this->api('9in_at_dead')->assertStatus(401);
    }

    public function testLocalModeWithoutClientSecret(): void
    {
        $this->rebuild(['nine.client_secret' => null]);
        $this->assertSame(HybridTokenVerifier::MODE_LOCAL, app(NineManager::class)->tokenVerifier()->mode());
        $this->api($this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'x']))->assertOk();
        $this->api('9in_at_opaque')->assertStatus(401);
        $this->assertSame([], $this->tenant->introspected);
        // Webhooks still work and evict nothing.
        $this->webhook(['event_type' => 'session.revoked', 'data' => ['sid' => 'sid_1']])->assertStatus(204);
    }

    public function testIntrospectMode(): void
    {
        $this->rebuild(['nine.api.mode' => 'introspect']);
        $token = $this->tenant->token(['aud' => 'https://api.acme.test', 'scope' => 'x']);
        $this->api($token)->assertOk();
        $this->api($token)->assertOk();
        $this->assertCount(2, $this->tenant->introspected);
    }

    public function testHmacTokensWithTheApiSecret(): void
    {
        $this->rebuild(['nine.api.secret' => 'shared-api-secret-of-sufficient-length', 'nine.api.mode' => 'local']);
        $claims = ['iss' => FakeTenant::ISSUER, 'sub' => 'app_m2m', 'aud' => 'https://api.acme.test', 'scope' => 'x', 'exp' => time() + 60];
        $sign   = function (string $secret) use ($claims) {
            $input = Jwt::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'at+jwt', 'kid' => 'hk'])) . '.' . Jwt::base64UrlEncode(json_encode($claims));
            return $input . '.' . Jwt::base64UrlEncode(hash_hmac('sha256', $input, $secret, true));
        };
        $this->api($sign('shared-api-secret-of-sufficient-length'))->assertOk()->assertJson(['sub' => 'app_m2m']);
        $this->api($sign('another-secret'))->assertStatus(401);
    }

    public function testIdTokensAreNotAcceptedAsBearerTokens(): void
    {
        $this->api($this->tenant->token(['aud' => 'https://api.acme.test'], null, 'JWT'))->assertStatus(401);
    }

    public function testAMisconfiguredVerifierDoesNotBreakWebhooks(): void
    {
        $this->rebuild(['nine.api.mode' => 'hybrid', 'nine.client_secret' => null]);
        $this->withoutExceptionHandling();
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class () extends \Illuminate\Foundation\Exceptions\Handler {
            public function __construct()
            {
            }

            public function report(\Throwable $e)
            {
            }
        });
        $this->webhook(['event_type' => 'session.revoked', 'data' => ['sid' => 'sid_1']])->assertStatus(204);
    }
}
