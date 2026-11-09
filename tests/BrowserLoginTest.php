<?php

namespace Nine\Laravel\Tests;

use Nine\Laravel\NineManager;
use Nine\OAuth2Client;

class BrowserLoginTest extends TestCase
{
    /**
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function startLogin(string $returnTo = '/dashboard'): array
    {
        $response = $this->get('/nine/login?return_to=' . urlencode($returnTo));
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $pending = session(NineManager::SESSION_PENDING)[$query['state']];
        return [$location, $query, $pending];
    }

    public function testLoginRedirectsToTheHostedPageWithPkce(): void
    {
        [$location, $query, $pending] = $this->startLogin();

        $this->assertStringStartsWith(FakeTenant::ISSUER . '/oauth/authorize?', $location);
        $this->assertSame('app_web', $query['client_id']);
        $this->assertSame('http://localhost/nine/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(OAuth2Client::codeChallenge($pending['verifier']), $query['code_challenge']);
        $this->assertSame('/dashboard', $pending['return_to']);
    }

    public function testCallbackSignsTheUserIn(): void
    {
        [, $query, $pending] = $this->startLogin();
        $this->tenant->nonce = $pending['nonce'];

        $this->get('/nine/callback?code=abc&state=' . $query['state'] . '&iss=' . urlencode(FakeTenant::ISSUER))
            ->assertRedirect('/dashboard');

        $this->assertSame('rt_authorization_code', session(NineManager::SESSION_TOKENS)['refresh_token']);
        $token = $this->tenant->callsTo(FakeTenant::ISSUER . '/oauth/token')[0];
        $this->assertSame('Basic ' . base64_encode('app_web:web_secret'), $token['headers']['Authorization']);

        $this->get('/dashboard')->assertOk()->assertSeeText('hello jane@acme.com');
        $this->get('/maybe')->assertSeeText('in');
    }

    public function testProtectedPagesRedirectToLogin(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect();
        $this->assertStringStartsWith(FakeTenant::ISSUER . '/oauth/authorize?', $response->headers->get('Location'));
        $this->get('/maybe')->assertSeeText('out');
    }

    public function testRejectsForgedOrReplayedCallbacks(): void
    {
        [, $query, $pending] = $this->startLogin();
        $this->tenant->nonce = $pending['nonce'];

        $this->get('/nine/callback?code=abc&state=forged')->assertStatus(400);
        $this->get('/nine/callback?code=abc&state=' . $query['state'])->assertRedirect('/dashboard');
        $this->get('/nine/callback?code=abc&state=' . $query['state'])->assertStatus(400);
    }

    public function testRejectsAWrongNonce(): void
    {
        [, $query] = $this->startLogin();
        $this->tenant->nonce = 'other';
        $this->get('/nine/callback?code=abc&state=' . $query['state'])->assertStatus(401);
        $this->assertNull(session(NineManager::SESSION_TOKENS));
    }

    public function testNoOpenRedirects(): void
    {
        foreach (['https://evil.test/x', '//evil.test', '/\\evil.test'] as $target) {
            [, , $pending] = $this->startLogin($target);
            $this->assertSame('/', $pending['return_to'], $target);
        }
    }

    public function testExpiredSessionsAreRefreshed(): void
    {
        $this->withSession([NineManager::SESSION_TOKENS => [
            'access_token'  => 'old',
            'id_token'      => $this->tenant->token(['aud' => 'app_web', 'email' => 'jane@acme.com'], null, 'JWT'),
            'refresh_token' => 'rt_old',
            'expires_at'    => time() - 5,
            'scope'         => 'openid',
            'return_to'     => null,
        ]]);
        $this->get('/dashboard')->assertOk();
        $this->assertSame('rt_refresh_token', session(NineManager::SESSION_TOKENS)['refresh_token']);
    }

    public function testRevokedRefreshTokensSignTheUserOut(): void
    {
        $this->tenant->tokenStatus   = 400;
        $this->tenant->tokenResponse = ['error' => 'invalid_grant'];
        $this->withSession([NineManager::SESSION_TOKENS => ['access_token' => 'old', 'id_token' => null, 'refresh_token' => 'rt_old', 'expires_at' => time() - 5, 'scope' => '', 'return_to' => null]]);
        $this->get('/maybe')->assertSeeText('out');
        $this->assertNull(session(NineManager::SESSION_TOKENS));
    }

    public function testLogoutEndsBothSessions(): void
    {
        $idToken = $this->tenant->token(['aud' => 'app_web'], null, 'JWT');
        $this->withSession([NineManager::SESSION_TOKENS => ['access_token' => 'a', 'id_token' => $idToken, 'refresh_token' => 'rt_x', 'expires_at' => time() + 600, 'scope' => '', 'return_to' => null]]);

        $response = $this->get('/nine/logout');
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(FakeTenant::ISSUER . '/oauth/logout?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($idToken, $query['id_token_hint']);
        $this->assertSame('http://localhost', rtrim($query['post_logout_redirect_uri'], '/'));
        $this->assertNull(session(NineManager::SESSION_TOKENS));
        $this->assertStringContainsString('token=rt_x', $this->tenant->callsTo(FakeTenant::ISSUER . '/oauth/revoke')[0]['body']);
    }

    public function testLogoutStillSignsOutWhenRevocationFails(): void
    {
        $this->tenant->revokeStatus = 503;
        $this->withSession([NineManager::SESSION_TOKENS => ['access_token' => 'a', 'id_token' => null, 'refresh_token' => 'rt_x', 'expires_at' => time() + 600, 'scope' => '', 'return_to' => null]]);

        $response = $this->get('/nine/logout');
        $this->assertStringStartsWith(FakeTenant::ISSUER . '/oauth/logout?', (string) $response->headers->get('Location'));
        $this->assertNull(session(NineManager::SESSION_TOKENS));
        $this->assertCount(1, $this->tenant->callsTo(FakeTenant::ISSUER . '/oauth/revoke'));
    }
}
