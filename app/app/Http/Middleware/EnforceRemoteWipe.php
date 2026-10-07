<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A device with a pending remote wipe gets 403 `wipe_required` on every API call except
 * the acknowledgement, so it learns about the wipe the next time it is online.
 */
final class EnforceRemoteWipe
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->attributes->get('auth_device');

        if ($device instanceof Device && $device->status === Device::STATUS_WIPE_PENDING
            && $request->route()?->getName() !== 'api.v1.sync.wipe-ack') {
            return response()->json([
                'error' => 'wipe_required',
                'message' => 'This device must erase its local data. Then call POST /api/v1/sync/wipe-ack.',
            ], 403);
        }

        return $next($request);
    }
}
