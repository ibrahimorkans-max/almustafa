<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\DeviceService;
use App\Domain\Hr\Attendance\DeviceClock;
use App\Domain\Sync\SyncRegistry;
use App\Domain\Sync\SyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SyncPushRequest;
use App\Http\Resources\AccountResource;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one sync contract shared by the PWA, the Flutter app and the gate agent.
 * See docs/api/openapi.yaml.
 */
final class SyncController extends Controller
{
    public function bootstrap(Request $request, SyncService $sync, SyncRegistry $registry): JsonResponse
    {
        /** @var LoginAccount $account */
        $account = $request->user();

        return response()->json([
            'server_time' => now()->toIso8601ZuluString('millisecond'),
            'protocol_version' => 1,
            'max_offline_hours' => (int) config('ricemill.sync.max_offline_hours'),
            'max_push_events' => (int) config('ricemill.sync.max_push_events'),
            'cursor' => $sync->cursor(),
            'types' => $registry->capabilities($account),
            'events' => $registry->eventCapabilities($account),
            'account' => new AccountResource($account),
        ]);
    }

    public function push(SyncPushRequest $request, SyncService $sync): JsonResponse
    {
        /** @var LoginAccount $account */
        $account = $request->user();
        app(DeviceClock::class)->set($request->validated('device_clock'));

        return response()->json([
            'results' => $sync->push($account, $this->device($request), $request->validated('events')),
            'cursor' => $sync->cursor(),
        ]);
    }

    public function pull(Request $request, SyncService $sync): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('ricemill.sync.max_pull_changes')],
        ]);

        /** @var LoginAccount $account */
        $account = $request->user();

        return response()->json($sync->pull(
            $account,
            (int) ($validated['cursor'] ?? 0),
            (int) ($validated['limit'] ?? config('ricemill.sync.max_pull_changes')),
        ));
    }

    public function wipeAck(Request $request, DeviceService $devices): JsonResponse
    {
        $device = $this->device($request);
        abort_unless($device->status === Device::STATUS_WIPE_PENDING, 409, 'No wipe was requested for this device.');

        $devices->acknowledgeWipe($device);

        return response()->json(['status' => 'wiped']);
    }

    private function device(Request $request): Device
    {
        /** @var Device */
        return $request->attributes->get('auth_device');
    }
}
