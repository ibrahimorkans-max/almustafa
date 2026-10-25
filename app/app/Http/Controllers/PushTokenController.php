<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Messaging\PartyStatement;
use App\Models\LoginAccount;
use App\Models\PartyPushToken;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /admin/push-token: a party signed in on its phone or browser turned notifications on — keep the
 * Firebase token, so its messages arrive there too. Only a party login can register a device.
 */
final class PushTokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Filament::auth()->user();
        $party = PartyStatement::partyOf($user instanceof LoginAccount ? $user : null);
        abort_if($party === null, 403);
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:4096'], 'device' => ['nullable', 'string', 'max:160']]);

        $hash = hash('sha256', $data['token']);
        $device = PartyPushToken::query()->where('party_id', $party->id)->where('token_hash', $hash)->first()
            ?? new PartyPushToken(['party_id' => $party->id, 'token_hash' => $hash]);
        $device->fill(['token' => $data['token'], 'login_account_id' => $user->id, 'device' => $data['device'] ?? null, 'last_used_at' => now()])->save();

        return response()->json(['ok' => true]);
    }
}
