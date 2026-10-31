<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Accounts\RoznamchaBudget;
use App\Models\Accounts\RoznamchaLine;
use App\Models\Accounts\RoznamchaPage;
use Carbon\CarbonImmutable;

/**
 * The roznamcha's daily budget: how much cash may be paid out (banam) on a day. The usual amount is set once;
 * a day can have its own (a big payment day, a holiday). What is paid out on a day is the cash paid on all of its
 * roznamcha pages. The pages show the budget, what is paid and what is left; a page that takes its day over the
 * budget is posted only with the reason (Roznamcha::post), and the list shows the day in red.
 */
final class RoznamchaBudgets
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /** Owner, Super Admin and whoever approves payments set the budget. */
    public static function canSet(): bool
    {
        $me = app(ActorContext::class)->account();

        return $me !== null && ($me->hasPermission('payment.approve') || $me->hasPermission('settings.manage'));
    }

    /** The usual daily amount in paise, or null when none is set. */
    public function usual(?string $entityId = null): ?int
    {
        $v = (string) $this->settings->get(SettingsService::ROZNAMCHA_BUDGET, $entityId ?? $this->tenant->entityId());

        return ctype_digit($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** The day's own amount, else the usual one; null: no budget. */
    public function budgetFor(CarbonImmutable $day): ?int
    {
        $own = RoznamchaBudget::query()->whereDate('budget_date', $day->toDateString())->value('amount_paise');

        return $own !== null ? (int) $own : $this->usual();
    }

    /** Cash paid out on the day's pages (all of them, checked or not). */
    public function paidOn(CarbonImmutable $day): int
    {
        return (int) RoznamchaLine::query()
            ->whereIn('roznamcha_page_id', RoznamchaPage::query()->whereDate('entry_date', $day->toDateString())->select('id'))
            ->sum('debit_paise');
    }

    /** @return array{budget: int|null, paid: int, left: int|null, over: bool, own: bool} */
    public function day(CarbonImmutable $day): array
    {
        $budget = $this->budgetFor($day);
        $paid = $this->paidOn($day);

        return ['budget' => $budget, 'paid' => $paid, 'left' => $budget === null ? null : $budget - $paid, 'over' => $budget !== null && $paid > $budget,
            'own' => RoznamchaBudget::query()->whereDate('budget_date', $day->toDateString())->exists()];
    }

    /** The usual amount (null or 0: no budget). */
    public function setUsual(?int $paise): void
    {
        $this->require();
        $entity = $this->tenant->requireEntityId();
        $this->settings->put(SettingsService::ROZNAMCHA_BUDGET, $paise !== null && $paise > 0 ? (string) $paise : '', $entity);
        $this->audit->record('roznamcha.budget.usual', null, null, null, ['paise' => $paise]);
    }

    /** A day's own amount (null: the day goes back to the usual one). */
    public function setDay(CarbonImmutable $day, ?int $paise, ?string $note = null): void
    {
        $this->require();
        $existing = RoznamchaBudget::query()->whereDate('budget_date', $day->toDateString())->first();
        if ($paise === null) {
            $existing?->delete();
        } else {
            if ($paise < 0) {
                throw new BusinessRuleViolation('The budget cannot be below zero.');
            }
            ($existing ?? new RoznamchaBudget(['budget_date' => $day->toDateString(), 'created_by' => $this->actor->accountId()]))
                ->fill(['amount_paise' => $paise, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null])->save();
        }
        $this->audit->record('roznamcha.budget.day', null, null, null, ['date' => $day->toDateString(), 'paise' => $paise]);
    }

    private function require(): void
    {
        if (! self::canSet()) {
            throw new BusinessRuleViolation('Setting the roznamcha budget needs the payment approval or settings right.');
        }
    }
}
