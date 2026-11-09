<?php

namespace Nine\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Nine\EventDeduplicator;
use Nine\Laravel\Events\EventFactory;
use Nine\Laravel\Events\NineEvent;
use Nine\Laravel\NineManager;

/**
 * POST /nine/webhook (after VerifyWebhookSignature): drops cached token answers the
 * event makes stale (token.revoked, session.revoked, user.*), then dispatches the specific
 * Nine\Laravel\Events\* class and EventReceived, once per event id (`nine.webhook.dedupe`).
 *
 * - First delivery: listeners run → 204. A listener exception → 500, the id is released
 *   and 9in retries.
 * - Retry or redelivery of a processed event → 204 without dispatching again.
 * - Same event still being processed by another request → 409, 9in retries later.
 *
 * Answer quickly; queue slow work in your listeners.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, Dispatcher $events, NineManager $nine)
    {
        $event = $request->attributes->get('nine.webhook');

        // Revocations take effect at once for bearer tokens on this app (hybrid mode).
        $nine->handleTokenEvent($event);

        $dispatch = function () use ($event, $events) {
            foreach (EventFactory::all($event, NineEvent::SOURCE_WEBHOOK) as $nineEvent) {
                $events->dispatch($nineEvent);
            }
        };

        if (!config('nine.webhook.dedupe', true)) {
            $dispatch();
            return new Response('', 204);
        }

        $outcome = (new EventDeduplicator($nine->cache(), (int) config('nine.webhook.dedupe_ttl', 259200), 300, 'nine:webhook:'))
            ->process((string) $event['id'], $dispatch);

        if ($outcome['status'] === EventDeduplicator::IN_PROGRESS) {
            return new JsonResponse(['error' => 'in_progress'], 409);
        }

        return new Response('', 204);
    }
}
