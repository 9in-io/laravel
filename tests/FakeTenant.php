<?php

namespace Nine\Laravel\Tests;

use Nine\Http\HttpClientInterface;
use Nine\Http\Response;
use Nine\Jwt\Jwt;

/** A fake 9in tenant: discovery, JWKS, token, revoke endpoints, and a signing key. */
class FakeTenant implements HttpClientInterface
{
    public const ISSUER = 'https://acme.9in.test';

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    /** @var array<int, array{method: string, url: string, headers: array, body: ?string}> */
    public $calls = [];

    /** @var string|null nonce for the next ID token */
    public $nonce;

    /** @var array<string, mixed> token endpoint response overrides */
    public $tokenResponse = [];

    /** @var int */
    public $tokenStatus = 200;

    /** @var callable|null token => introspection answer (default: active with the token's claims) */
    public $introspection;

    /** @var string[] tokens sent to the introspection endpoint */
    public $introspected = [];

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        switch ($method . ' ' . $url) {
            case 'GET ' . self::ISSUER . '/.well-known/openid-configuration':
                return $this->json([
                    'issuer'                 => self::ISSUER,
                    'authorization_endpoint' => self::ISSUER . '/oauth/authorize',
                    'token_endpoint'         => self::ISSUER . '/oauth/token',
                    'userinfo_endpoint'      => self::ISSUER . '/oauth/userinfo',
                    'jwks_uri'               => self::ISSUER . '/.well-known/jwks.json',
                    'revocation_endpoint'    => self::ISSUER . '/oauth/revoke',
                    'introspection_endpoint' => self::ISSUER . '/oauth/introspect',
                    'end_session_endpoint'   => self::ISSUER . '/oauth/logout',
                ]);
            case 'GET ' . self::ISSUER . '/.well-known/jwks.json':
                $details = openssl_pkey_get_details($this->key);
                return $this->json(['keys' => [[
                    'kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
                    'n'   => Jwt::base64UrlEncode($details['rsa']['n']),
                    'e'   => Jwt::base64UrlEncode($details['rsa']['e']),
                ]]]);
            case 'POST ' . self::ISSUER . '/oauth/token':
                parse_str((string) $body, $form);
                return $this->json(array_merge([
                    'access_token'  => $this->token(['aud' => 'https://api.acme.test', 'scope' => 'openid profile email']),
                    'id_token'      => $this->token(['aud' => 'app_web', 'nonce' => $this->nonce, 'email' => 'jane@acme.com', 'name' => 'Jane'], null, 'JWT'),
                    'refresh_token' => 'rt_' . ($form['grant_type'] ?? ''),
                    'expires_in'    => 900,
                    'scope'         => 'openid profile email offline_access',
                ], $this->tokenResponse), $this->tokenStatus);
            case 'POST ' . self::ISSUER . '/oauth/revoke':
                return new Response(200, [], '');
            case 'POST ' . self::ISSUER . '/oauth/introspect':
                parse_str((string) $body, $form);
                $this->introspected[] = $form['token'];
                if ($this->introspection) {
                    return $this->json(($this->introspection)($form['token']));
                }
                return $this->json(['active' => true]);
        }
        return $this->json(['error' => 'not_found'], 404);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function token(array $claims = [], $key = null, string $typ = 'at+jwt'): string
    {
        $payload = array_merge(['iss' => self::ISSUER, 'sub' => 'usr_1', 'client_id' => 'app_web', 'sid' => 'sid_1', 'jti' => bin2hex(random_bytes(6)), 'iat' => time(), 'exp' => time() + 900], $claims);
        $input   = Jwt::base64UrlEncode(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => $typ])) . '.' . Jwt::base64UrlEncode(json_encode($payload));
        openssl_sign($input, $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256);
        return $input . '.' . Jwt::base64UrlEncode($signature);
    }

    /**
     * @return array<int, array{method: string, url: string, headers: array, body: ?string}>
     */
    public function callsTo(string $url): array
    {
        return array_values(array_filter($this->calls, function ($call) use ($url) {
            return strpos($call['url'], $url) === 0;
        }));
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['content-type' => 'application/json'], json_encode($data));
    }
}
