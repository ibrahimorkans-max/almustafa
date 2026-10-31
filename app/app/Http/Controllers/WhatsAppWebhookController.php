<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\WhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * /api/webhooks/whatsapp/{scope}, called by Meta (no sign-in): GET checks the address with the verify token,
 * POST brings events signed with the app's secret. Anything unsigned or for an unknown number is refused.
 */
final class WhatsAppWebhookController
{
    public function verify(Request $request, string $scope, WhatsAppWebhook $webhook): Response
    {
        // PHP turns "hub.mode" into "hub_mode" in the query.
        $challenge = $webhook->knownScope($scope)
            ? $webhook->verify($scope, $request->query('hub_mode'), $request->query('hub_verify_token'), $request->query('hub_challenge'))
            : null;

        return $challenge === null
            ? response('Verify token does not match.', 403)
            : response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, string $scope, WhatsAppWebhook $webhook): Response
    {
        $body = $request->getContent();
        if (! $webhook->knownScope($scope) || ! $webhook->signed($scope, $body, $request->header('X-Hub-Signature-256'))) {
            Log::warning('WhatsApp webhook refused: unknown number or bad signature (is the app secret saved?)', ['scope' => $scope]);

            return response('Signature does not match.', 403);
        }
        $payload = json_decode($body, true);
        $webhook->receive($scope, is_array($payload) ? $payload : []);

        return response('OK', 200);
    }
}
