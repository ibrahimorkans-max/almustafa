<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\Integrations;
use Illuminate\Http\Response;

/**
 * GET /firebase-messaging-sw.js: the service worker that shows party notifications while the app is
 * closed. It must be served from the site root; it carries only the public web app settings.
 */
final class FirebaseServiceWorkerController
{
    public const SDK = 'https://www.gstatic.com/firebasejs/10.12.2/';

    public function __invoke(): Response
    {
        $config = json_encode(Integrations::firebase()['web'], JSON_UNESCAPED_SLASHES);
        $js = "importScripts('".self::SDK."firebase-app-compat.js');\n"
            ."importScripts('".self::SDK."firebase-messaging-compat.js');\n"
            ."firebase.initializeApp({$config});\n"
            ."const messaging = firebase.messaging();\n"
            // Notification messages are shown by Firebase; a click opens the party's account.
            ."self.addEventListener('notificationclick', (event) => { event.notification.close(); event.waitUntil(clients.openWindow('/admin/my-account')); });\n";

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
    }
}
