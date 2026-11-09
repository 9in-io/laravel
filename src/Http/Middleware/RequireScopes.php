<?php

namespace Nine\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `nine.scope:orders:read,orders:write`: the bearer access token must carry every scope.
 * Put it after `nine`.
 */
class RequireScopes
{
    public function handle(Request $request, Closure $next, string ...$scopes)
    {
        $claims  = auth('nine')->claims() ?? [];
        $granted = preg_split('/\s+/', (string) ($claims['scope'] ?? ''));
        $missing = array_values(array_diff($scopes, $granted));

        if ($missing) {
            $response = new JsonResponse(['error' => 'insufficient_scope', 'error_description' => 'requires ' . implode(' ', $missing)], 403);
            $response->headers->set('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="' . implode(' ', $scopes) . '"');
            return $response;
        }

        return $next($request);
    }
}
