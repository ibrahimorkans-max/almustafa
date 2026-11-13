<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Console\Command;

/**
 * For the server's administrator: a login's devices and its "trust new device" requests, with the mill each
 * request is filed under (it shows only in that mill's Approvals). With --trust the newest device waiting for
 * approval is trusted from the server, for when nobody in the ERP can approve it (an Owner's new device needs
 * another Owner); its pending request is withdrawn.
 */
final class AuthDevicesCommand extends Command
{
    protected $signature = 'auth:devices
        {username : The login}
        {--trust : Trust the newest device waiting for approval}';

    protected $description = 'Show a login\'s devices and approval requests; --trust approves the newest waiting device from the server';

    public function handle(TenantContext $tenant, AuthContextBinder $binder, DeviceService $devices, ApprovalService $approvals): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $account = $tenant->runAsSystem(fn () => LoginAccount::query()->with(['role', 'entity'])->where('username', $username)->first());
        if ($account === null) {
            $this->error("No login {$username}.");

            return self::FAILURE;
        }
        $binder->bind($account);

        $this->line("{$account->username}: {$account->role?->name}, mill {$account->entity?->code}".($account->isOwner() ? ' (an Owner: a new device needs ANOTHER Owner to approve it)' : ''));
        $list = $tenant->runAsSystem(fn () => Device::query()->where('login_account_id', $account->id)->orderByDesc('created_at')->get());
        $this->table(['Device', 'Kind', 'Status', 'Approved', 'First seen (PKT)', 'Last seen (PKT)'], $list->map(fn (Device $d) => [
            $d->name ?? '-', $d->platform, $d->status, $d->isTrusted() ? 'yes' : 'WAITING',
            $d->created_at?->timezone('Asia/Karachi')->format('d M H:i'), $d->last_seen_at?->timezone('Asia/Karachi')->format('d M H:i') ?? '-',
        ])->all());

        $requests = $tenant->runAsSystem(fn () => ApprovalRequest::query()->with('entity')->where('action', TrustDevice::KEY)
            ->where('maker_login_account_id', $account->id)->orderByDesc('created_at')->limit(10)->get());
        $this->table(['Approval request', 'Mill (shows in its Approvals)', 'Status', 'Asked (PKT)'], $requests->map(fn (ApprovalRequest $r) => [
            $r->summary, $r->entity->code ?? '-', $r->isPending() ? 'pending' : ($r->status === ApprovalRequest::STATUS_PENDING ? 'expired' : $r->status),
            $r->created_at?->timezone('Asia/Karachi')->format('d M H:i'),
        ])->all());

        if (! $this->option('trust')) {
            return self::SUCCESS;
        }
        $waiting = $list->first(fn (Device $d) => ! $d->isTrusted() && $d->isUsable() && $d->platform !== 'agent');
        if ($waiting === null) {
            $this->info('No device is waiting for approval.');

            return self::SUCCESS;
        }
        $devices->trust($waiting, null, 'server_command');
        foreach ($requests->filter(fn (ApprovalRequest $r) => $r->status === ApprovalRequest::STATUS_PENDING && ($r->payload['device_id'] ?? null) === $waiting->id) as $r) {
            $approvals->cancel($r, $account);   // withdrawn in the requester's name: the device is trusted now
        }
        $this->info("Trusted: '".($waiting->name ?? $waiting->platform)."' of {$account->username}. They can sign in on it now.");

        return self::SUCCESS;
    }
}
