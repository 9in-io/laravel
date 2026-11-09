<?php

namespace Nine\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nine\Exceptions\NineException;
use Nine\Webhook;

/**
 * Verifies the `9in-signature` of a webhook (raw body, request path, 5-minute window)
 * and puts the event in `$request->attributes->get('nine.webhook')`. Use it on your own
 * route with `->middleware('nine.webhook')` if you do not use /nine/webhook.
 */
class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next)
    {
        $secrets = array_values(array_filter([config('nine.webhook.secret'), config('nine.webhook.previous_secret')]));
        if (!$secrets) {
            return new JsonResponse(['error' => 'webhook_not_configured'], 500);
        }

        try {
            $event = Webhook::verify(
                $secrets,
                $request->getContent(),
                $request->header('9in-signature'),
                (string) parse_url($request->getRequestUri(), PHP_URL_PATH),
                (int) config('nine.webhook.tolerance', 300),
                $request->getMethod()
            );
        } catch (NineException $e) {
            return new JsonResponse(['error' => $e->getError()], $e->getStatus() ?? 400);
        }

        $request->attributes->set('nine.webhook', $event);

        return $next($request);
    }
}
