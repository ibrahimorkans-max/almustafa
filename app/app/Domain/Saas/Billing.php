<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPaymentMethod;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SaaS billing: monthly invoices for a customer's plan, paid by bank transfer, mobile wallet or Raast (Platform → Payment methods). The customer reports a
 * transfer (reference and receipt); the platform admin records the money received. Unpaid past the due
 * date: reminders; past the grace days: read-only ("payment due"); optionally paused later. Paying
 * every overdue invoice makes the customer active again at once.
 */
final class Billing
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The month's invoice for the customer's plan; null when nothing is billed (lifetime access, a trial,
     * paused, no paid plan, already issued). `afterTrial`: the first invoice when the free trial ends.
     */
    public function issueMonthly(Customer $customer, CarbonImmutable $month, bool $afterTrial = false): ?SaasInvoice
    {
        if ($customer->isLifetime()) {
            return null;
        }
        if (! ($afterTrial ? $customer->trialEnded() : in_array($customer->status, [Customer::ACTIVE, Customer::PAST_DUE], true))) {
            return null;
        }
        $plan = $customer->plan_id === null ? null : Plan::query()->find($customer->plan_id);
        if ($plan === null || $plan->price_paise_monthly <= 0) {
            return null;
        }
        $start = $month->startOfMonth();

        return DB::transaction(function () use ($customer, $plan, $start): ?SaasInvoice {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();   // one monthly invoice, even if two runs meet
            $exists = SaasInvoice::query()->where('customer_id', $customer->id)->where('kind', SaasInvoice::MONTHLY)
                ->whereDate('period_start', $start->toDateString())->where('status', '!=', SaasInvoice::VOID)->exists();
            if ($exists) {
                return null;
            }

            return $this->issue($customer, "{$plan->name} plan, ".$start->format('F Y'), $plan->price_paise_monthly,
                SaasInvoice::MONTHLY, $start, $start->endOfMonth(), $plan->id);
        });
    }

    public function issue(Customer $customer, string $description, int $amountPaise, string $kind = SaasInvoice::OTHER,
        ?CarbonImmutable $periodStart = null, ?CarbonImmutable $periodEnd = null, ?string $planId = null, ?int $dueDays = null): SaasInvoice
    {
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('An invoice needs an amount above zero.');
        }
        $invoice = DB::transaction(fn () => SaasInvoice::query()->create([
            'customer_id' => $customer->id,
            'number' => $this->nextNumber(),
            'kind' => $kind,
            'description' => $description,
            'plan_id' => $planId,
            'period_start' => $periodStart?->toDateString(),
            'period_end' => $periodEnd?->toDateString(),
            'amount_paise' => $amountPaise,
            'status' => SaasInvoice::OPEN,
            'issued_at' => now(),
            'due_at' => CarbonImmutable::now('Asia/Karachi')->addDays($dueDays ?? (int) config('ricemill.saas.billing.due_days'))->endOfDay()->utc(),
        ]));
        $this->tellOwners($customer, "Invoice {$invoice->number}: ".Units::paiseToRupees($amountPaise),
            "{$description}. Due by ".$this->day($invoice->due_at).'. Pay with one of the ways under Administration → My subscription, then press "I\'ve paid".', 'info');

        return $invoice;
    }

    /** The customer says it has paid: the bank reference, the day, and a photo of the receipt if it has one. */
    /**
     * The customer says it has paid: the transaction ID (stored cleaned, so it matches the bank statement),
     * which way it paid, how much, the day, and a photo of the receipt if it has one. A transaction ID is
     * accepted for one invoice only: the same receipt cannot pay twice.
     */
    public function reportPayment(SaasInvoice $invoice, LoginAccount $by, string $transactionId, string $paidOn, ?string $note, ?string $receiptPath,
        ?string $methodId = null, ?int $amountPaise = null): void
    {
        if ($invoice->status !== SaasInvoice::OPEN) {
            throw new BusinessRuleViolation('This invoice is not open.');
        }
        if ($by->customerId() !== $invoice->customer_id || ! $by->isOwner()) {
            throw new BusinessRuleViolation('Only the company\'s Owner can report a payment.');
        }
        $transactionId = SaasInvoice::cleanTransactionId($transactionId);
        if (preg_match('/^[A-Z0-9][A-Z0-9\/._-]{3,59}$/', $transactionId) !== 1) {
            throw new BusinessRuleViolation('Enter the transaction ID exactly as on the receipt: letters and numbers, at least 4.');
        }
        $usedOn = SaasInvoice::query()->whereKeyNot($invoice->id)->where('status', '!=', SaasInvoice::VOID)
            ->where(fn ($q) => $q->where('reported_reference', $transactionId)->orWhere('payment_reference', $transactionId))->value('number');
        if ($usedOn !== null) {
            throw new BusinessRuleViolation("This transaction ID was already given for invoice {$usedOn}. Check it on your receipt.");
        }
        if ($methodId !== null && ! SaasPaymentMethod::query()->whereKey($methodId)->where('active', true)->exists()) {
            throw new BusinessRuleViolation('Choose one of the ways to pay shown.');
        }
        if ($amountPaise !== null && $amountPaise <= 0) {
            throw new BusinessRuleViolation('Enter the amount you sent.');
        }
        $invoice->update([
            'reported_at' => now(), 'reported_reference' => $transactionId, 'reported_method_id' => $methodId,
            'reported_amount_paise' => $amountPaise ?? $invoice->amount_paise, 'reported_paid_on' => $paidOn,
            'reported_note' => $note, 'receipt_path' => $receiptPath ?? $invoice->receipt_path, 'reported_by' => $by->id,
        ]);
        $via = $methodId !== null ? ' by '.SaasPaymentMethod::query()->whereKey($methodId)->value('name') : '';
        $this->tellPlatform("Payment reported: {$invoice->number}",
            "{$invoice->customer->name} says it paid ".Units::paiseToRupees((int) $invoice->reported_amount_paise)."{$via} on {$paidOn}, transaction ID {$transactionId}. Check it reached you and mark it paid.", 'info');
    }

    /** The platform admin records the money received; a customer with nothing left overdue is active again. */
    public function markPaid(SaasInvoice $invoice, LoginAccount $by, int $amountPaise, string $paidOn, string $reference, ?string $note): void
    {
        $this->assertPlatform($by);
        if ($invoice->status !== SaasInvoice::OPEN) {
            throw new BusinessRuleViolation('This invoice is not open.');
        }
        if ($amountPaise < $invoice->amount_paise) {
            throw new BusinessRuleViolation('The amount received is less than the invoice. Record it when the full amount has arrived.');
        }
        $reference = SaasInvoice::cleanTransactionId($reference);
        $paidBefore = SaasInvoice::query()->whereKeyNot($invoice->id)->where('status', SaasInvoice::PAID)->where('payment_reference', $reference)->value('number');
        if ($paidBefore !== null) {
            throw new BusinessRuleViolation("Transaction {$reference} already paid invoice {$paidBefore}. One payment pays one invoice.");
        }
        $invoice->update([
            'status' => SaasInvoice::PAID, 'paid_at' => CarbonImmutable::parse($paidOn, 'Asia/Karachi')->setTimeFrom(now('Asia/Karachi'))->utc(),
            'paid_amount_paise' => $amountPaise, 'payment_reference' => $reference, 'payment_note' => $note, 'recorded_by' => $by->id,
        ]);
        $customer = $invoice->customer;
        $this->tellOwners($customer, "Payment received: {$invoice->number}", 'Thank you. '.Units::paiseToRupees($amountPaise).' received.', 'success');
        $this->restore($customer);
    }

    /**
     * Lifetime access, given by the product owner: active for good, no trial end, no more invoices, never
     * read-only or paused for a bill. Its open invoices can be cancelled at the same time.
     */
    public function grantLifetime(Customer $customer, LoginAccount $by, string $note, bool $cancelOpen): void
    {
        $this->assertPlatform($by);
        $customer->update(['lifetime' => true, 'lifetime_since' => now(), 'status' => Customer::ACTIVE, 'trial_ends_at' => null]);
        $cancelled = 0;
        if ($cancelOpen) {
            foreach (SaasInvoice::query()->where('customer_id', $customer->id)->where('status', SaasInvoice::OPEN)->get() as $invoice) {
                $invoice->update(['status' => SaasInvoice::VOID, 'void_reason' => 'Lifetime access given']);
                $cancelled++;
            }
        }
        $this->audit->record('saas.customer.lifetime_granted', $customer, null, null, ['note' => $note, 'invoices_cancelled' => $cancelled]);
        $this->tellOwners($customer, 'You have lifetime access', 'Thank you for working with us. No more payments are due for your subscription.', 'success');
        app(CustomerAccess::class)->forget();
    }

    /** Lifetime access ends: the customer stays active and is billed again from the next monthly run. */
    public function revokeLifetime(Customer $customer, LoginAccount $by, string $note): void
    {
        $this->assertPlatform($by);
        $customer->update(['lifetime' => false, 'lifetime_since' => null]);
        $this->audit->record('saas.customer.lifetime_ended', $customer, null, null, ['note' => $note]);
        app(CustomerAccess::class)->forget();
    }

    public function void(SaasInvoice $invoice, LoginAccount $by, string $reason): void
    {
        $this->assertPlatform($by);
        if ($invoice->status !== SaasInvoice::OPEN) {
            throw new BusinessRuleViolation('Only an open invoice can be cancelled.');
        }
        $invoice->update(['status' => SaasInvoice::VOID, 'void_reason' => $reason]);
        $this->audit->record('saas.invoice.voided', $invoice, null, null, ['reason' => $reason]);
        $this->restore($invoice->customer);
    }

    /**
     * Daily: reminders (3 days before the due date, on it, and once overdue), read-only past the grace
     * days, paused past `suspend_after_days` when set. Returns what was done, for the command's output.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $lines = [];
        $grace = (int) config('ricemill.saas.billing.grace_days');
        $suspendAfter = (int) config('ricemill.saas.billing.suspend_after_days');
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();

        // A free trial that has ended gets its first invoice, so the customer can pay from the payment page.
        foreach (Customer::query()->where('status', Customer::TRIAL)->where('lifetime', false)->where('trial_ends_at', '<', now())->get() as $ended) {
            $first = $this->issueMonthly($ended, CarbonImmutable::now('Asia/Karachi'), afterTrial: true);
            if ($first !== null) {
                $lines[] = "{$ended->code}: trial ended, {$first->number} issued.";
            }
        }

        $open = SaasInvoice::query()->with('customer')->where('status', SaasInvoice::OPEN)->orderBy('due_at')->get();
        foreach ($open as $invoice) {
            $due = CarbonImmutable::parse($invoice->due_at)->timezone('Asia/Karachi')->startOfDay();
            $stage = match (true) {
                $due->lt($today) => 'overdue',
                $due->eq($today) => 'due',
                $due->lte($today->addDays(3)) => 'due_soon',
                default => null,
            };
            if ($stage !== null && $stage !== $invoice->last_reminder) {
                $amount = Units::paiseToRupees($invoice->amount_paise);
                [$title, $body, $color] = match ($stage) {
                    'due_soon' => ["Invoice {$invoice->number} is due on ".$due->format('d M Y'), "{$amount}. Pay with one of the ways under My subscription, then press I've paid.", 'info'],
                    'due' => ["Invoice {$invoice->number} is due today", "{$amount}. Pay with one of the ways under My subscription, then press I've paid.", 'warning'],
                    default => ["Invoice {$invoice->number} is overdue", "{$amount} was due on ".$due->format('d M Y').'. Unless it is paid within '
                        .$grace.' days of that date, your system becomes read-only.', 'danger'],
                };
                $this->tellOwners($invoice->customer, $title, $body, $color);
                $invoice->update(['last_reminder' => $stage]);
                $lines[] = "{$invoice->number}: {$stage} reminder.";
            }
        }

        foreach ($open->groupBy('customer_id') as $invoices) {
            /** @var SaasInvoice $oldest */
            $oldest = $invoices->first();
            $customer = $oldest->customer;
            if ($customer->isLifetime()) {
                continue;   // lifetime access: never read-only or paused for a bill
            }
            $late = $oldest->daysOverdue();
            if ($suspendAfter > 0 && $late >= $suspendAfter && in_array($customer->status, [Customer::ACTIVE, Customer::PAST_DUE], true)) {
                $customer->update(['status' => Customer::SUSPENDED]);
                $this->audit->record('saas.customer.paused', $customer, null, null, ['reason' => "invoice {$oldest->number} unpaid {$late} days"]);
                $this->tellPlatform("{$customer->name} paused", "Invoice {$oldest->number} unpaid {$late} days after its due date.");
                $lines[] = "{$customer->code}: paused, {$oldest->number} unpaid {$late} days.";
            } elseif ($late > $grace && $customer->status === Customer::ACTIVE) {
                $customer->update(['status' => Customer::PAST_DUE]);
                $this->tellOwners($customer, 'Your system is now read-only',
                    "Invoice {$oldest->number} is {$late} days overdue. You can still see everything, but not record or change anything until it is paid.", 'danger');
                $this->tellPlatform("{$customer->name} is read-only", "Invoice {$oldest->number} is {$late} days overdue.");
                $lines[] = "{$customer->code}: read-only, {$oldest->number} {$late} days overdue.";
            }
        }
        app(CustomerAccess::class)->forget();

        return $lines;
    }

    /** @return array{open: Collection<int, SaasInvoice>, overdue: Collection<int, SaasInvoice>, due_paise: int} */
    public function standing(string $customerId): array
    {
        $open = SaasInvoice::query()->where('customer_id', $customerId)->where('status', SaasInvoice::OPEN)->orderBy('due_at')->get();

        return ['open' => $open, 'overdue' => $open->filter(fn (SaasInvoice $i) => $i->isOverdue())->values(), 'due_paise' => (int) $open->sum('amount_paise')];
    }

    /** @return \Illuminate\Support\Collection<int, LoginAccount> */
    public function owners(Customer $customer): \Illuminate\Support\Collection
    {
        return $this->tenant->runAsSystem(fn () => LoginAccount::query()->where('status', LoginAccount::STATUS_ACTIVE)
            ->whereIn('entity_id', Entity::query()->where('customer_id', $customer->id)->select('id'))
            ->whereIn('role_id', Role::query()->where('is_super_admin', true)->select('id'))->get());
    }

    /**
     * After a payment (or a cancelled invoice): read-only ends when nothing is overdue past the grace days;
     * a paused customer, or one whose trial ended, opens again once nothing is overdue / nothing is open.
     */
    private function restore(Customer $customer): void
    {
        $customer->refresh();
        $open = SaasInvoice::query()->where('customer_id', $customer->id)->where('status', SaasInvoice::OPEN);
        $grace = (int) config('ricemill.saas.billing.grace_days');
        $reopen = match (true) {
            $customer->status === Customer::PAST_DUE => ! (clone $open)->where('due_at', '<', now()->subDays($grace))->exists(),
            $customer->status === Customer::SUSPENDED => ! (clone $open)->where('due_at', '<', now())->exists(),
            $customer->trialEnded() => ! (clone $open)->exists(),
            default => false,
        };
        if ($reopen) {
            $customer->update(['status' => Customer::ACTIVE, 'trial_ends_at' => null]);
            $this->tellOwners($customer, 'Your system is fully open again', 'Thank you for your payment.', 'success');
            app(CustomerAccess::class)->forget();
        }
    }

    /** SUB-2026-0001: one gap-free series for the platform, by calendar year. */
    private function nextNumber(): string
    {
        $year = CarbonImmutable::now('Asia/Karachi')->year;
        $key = "invoice:{$year}";
        DB::table('saas_counters')->insertOrIgnore(['key' => $key, 'value' => 0]);
        $value = (int) DB::table('saas_counters')->where('key', $key)->lockForUpdate()->value('value') + 1;
        DB::table('saas_counters')->where('key', $key)->update(['value' => $value]);

        return sprintf('%s-%d-%04d', config('ricemill.saas.billing.number_prefix'), $year, $value);
    }

    private function assertPlatform(LoginAccount $by): void
    {
        if (! $by->isPlatformAdmin()) {
            throw new BusinessRuleViolation('Only the platform admin can do this.');
        }
    }

    private function tellOwners(Customer $customer, string $title, string $body, string $color): void
    {
        self::notify($this->owners($customer), $title, $body, $color);
    }

    private function tellPlatform(string $title, string $body, string $color = 'warning'): void
    {
        self::notify($this->tenant->runAsSystem(fn () => LoginAccount::query()->where('is_platform_admin', true)
            ->where('status', LoginAccount::STATUS_ACTIVE)->get()), $title, $body, $color);
    }

    /**
     * A bell notice, sent as the system: the notice is a queued job that loads its recipients again, and
     * from inside one customer the platform's accounts (another customer) would not be found.
     *
     * @param  \Illuminate\Support\Collection<int, LoginAccount>  $to
     */
    public static function notify(\Illuminate\Support\Collection $to, string $title, string $body, string $color = 'info'): void
    {
        if ($to->isEmpty()) {
            return;
        }
        app(TenantContext::class)->runAsSystem(fn () => Notification::make()->title($title)->body($body)
            ->icon('heroicon-o-document-text')->status($color)->sendToDatabase($to));
    }

    private function day(\DateTimeInterface $at): string
    {
        return CarbonImmutable::parse($at)->timezone('Asia/Karachi')->format('d M Y');
    }
}
