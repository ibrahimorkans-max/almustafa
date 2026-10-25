<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Daily: tells a customer's Owners (bell notice) when its free trial ends in 3 days and in 1 day.
 */
final class SaasTrialRemindersCommand extends Command
{
    protected $signature = 'saas:trial-reminders';

    protected $description = 'Remind customers\' Owners that their free trial is ending';

    private const DAYS_BEFORE = [3, 1];

    public function handle(TenantContext $tenant): int
    {
        return $tenant->runAsSystem(function (): int {
            $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
            $sent = 0;
            foreach (self::DAYS_BEFORE as $days) {
                $day = $today->addDays($days);
                $customers = Customer::query()->where('status', Customer::TRIAL)
                    ->whereBetween('trial_ends_at', [$day->utc(), $day->endOfDay()->utc()])->get();
                foreach ($customers as $customer) {
                    $owners = LoginAccount::query()->where('status', LoginAccount::STATUS_ACTIVE)
                        ->whereIn('entity_id', Entity::query()->where('customer_id', $customer->id)->select('id'))
                        ->whereIn('role_id', Role::query()->where('is_super_admin', true)->select('id'))->get();
                    if ($owners->isEmpty()) {
                        continue;
                    }
                    $contact = trim((string) config('ricemill.saas.contact'));
                    Notification::make()->warning()->icon('heroicon-o-clock')
                        ->title($days === 1 ? 'Your free trial ends tomorrow' : "Your free trial ends in {$days} days")
                        ->body('On '.$customer->trial_ends_at?->timezone('Asia/Karachi')->format('d M Y').' sign-in stops until a plan is paid for. Your data stays safe.'
                            .($contact === '' ? ' Contact us to continue.' : " Contact us to continue: {$contact}."))
                        ->sendToDatabase($owners);
                    $sent += $owners->count();
                    $this->line("{$customer->code}: trial ends in {$days} ".($days === 1 ? 'day' : 'days').", {$owners->count()} told.");
                }
            }
            $this->info("{$sent} reminders sent.");

            return self::SUCCESS;
        });
    }
}
