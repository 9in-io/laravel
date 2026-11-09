<?php

namespace Nine\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nine\Laravel\NineManager;

/**
 * `nine` middleware (guard `nine`; pass another guard name as the second parameter):
 *
 *   ->middleware('nine')            401 when signed out
 *   ->middleware('nine:redirect')   browsers are sent to the 9in login, APIs get 401
 *   ->middleware('nine:passive')    continue either way (auth('nine')->user() may be null)
 */
class Authenticate
{
    /** @var Auth */
    protected $auth;

    /** @var NineManager */
    protected $nine;

    public function __construct(Auth $auth, NineManager $nine)
    {
        $this->auth = $auth;
        $this->nine = $nine;
    }

    public function handle(Request $request, Closure $next, string $mode = 'strict', string $guard = 'nine')
    {
        if ($this->auth->guard($guard)->check()) {
            $this->auth->shouldUse($guard);
            return $next($request);
        }

        if ($mode === 'passive') {
            return $next($request);
        }

        if ($mode === 'redirect' && $request->isMethod('GET') && !$request->expectsJson() && $request->hasSession()) {
            return $this->nine->redirectToLogin($request->session(), $request->getRequestUri());
        }

        $response = new JsonResponse(['error' => 'unauthorized', 'error_description' => 'Sign in with 9in or send a valid Bearer token.'], 401);
        $response->headers->set('WWW-Authenticate', 'Bearer realm="9in"');
        return $response;
    }
}
