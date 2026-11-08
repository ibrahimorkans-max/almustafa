<?php

declare(strict_types=1);

namespace App\Domain\Usage;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Usage\ProviderExpense;
use App\Models\Usage\ServicePrice;
use App\Models\Usage\ServiceUsage;
use App\Models\Usage\UsageStatement;
use App\Support\Export\XlsxWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The usage figures every usage screen shows, from the usage records themselves (nothing typed in, nothing kept
 * aside): totals by channel, success and failure, estimated / actual / charged cost, and the same by company, mill,
 * provider, category, module and day. The SaaS owner sees every company (as the system); a mill's people see their
 * own mill or company only — the database's row security decides that, and the screen asks again (`$customerId`).
 */
final class UsageReport
{
    public const PERIODS = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days',
        'month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Custom dates'];

    /**
     * @param  array{period?: string, from?: ?string, to?: ?string, channel?: ?string, customer?: ?string, entity?: ?string, provider?: ?string}  $filters
     */
    public function __construct(private readonly array $filters, private readonly bool $platform) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the period in Pakistan time, as UTC bounds */
    public function range(): array
    {
        $now = CarbonImmutable::now('Asia/Karachi');
        [$from, $to] = match ($this->filters['period'] ?? '30d') {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'custom' => [CarbonImmutable::parse($this->filters['from'] ?? $now->subDays(29)->toDateString(), 'Asia/Karachi')->startOfDay(),
                CarbonImmutable::parse($this->filters['to'] ?? $now->toDateString(), 'Asia/Karachi')->endOfDay()],
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };

        return [$from->utc(), $to->utc()];
    }

    /** @return Builder<ServiceUsage> */
    public function query(): Builder
    {
        [$from, $to] = $this->range();

        return ServiceUsage::query()->whereBetween('occurred_at', [$from, $to])
            ->when($this->filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($this->filters['customer'] ?? null, fn ($q, $c) => $q->where('customer_id', $c))
            ->when($this->filters['entity'] ?? null, fn ($q, $e) => $q->where('entity_id', $e))
            ->when($this->filters['provider'] ?? null, fn ($q, $p) => $q->where('provider', $p));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function read(callable $fn): mixed
    {
        return $this->platform ? app(TenantContext::class)->runAsSystem($fn) : $fn();
    }

    /** @return array<string, mixed> the headline figures */
    public function totals(): array
    {
        return $this->read(function (): array {
            $row = $this->query()->selectRaw("
                count(*) filter (where channel = 'api') as api, count(*) filter (where channel = 'sms') as sms_calls,
                coalesce(sum(quantity) filter (where channel = 'sms'), 0) as sms, coalesce(sum(quantity) filter (where channel = 'whatsapp'), 0) as whatsapp,
                coalesce(sum(quantity) filter (where channel = 'email'), 0) as email, coalesce(sum(quantity) filter (where channel = 'push'), 0) as push,
                count(*) filter (where success) as ok, count(*) filter (where not success) as failed,
                coalesce(sum(estimated_cost), 0) as estimated, coalesce(sum(charge), 0) as charged,
                coalesce(sum(actual_cost) filter (where actual_currency = 'USD'), 0) as actual_usd,
                coalesce(sum(actual_cost) filter (where actual_currency is not null and actual_currency <> 'USD'), 0) as actual_other,
                count(*) as calls")->first()?->toArray() ?? [];
            [$from, $to] = $this->range();
            $row['provider_billed'] = $this->platform
                ? (string) ProviderExpense::query()->whereBetween('period', [$from->startOfMonth()->toDateString(), $to->toDateString()])->sum('amount') : null;
            $row['unreconciled'] = $this->platform ? bcsub((string) $row['estimated'], (string) ($row['provider_billed'] ?? '0'), 2) : null;

            return $row;
        });
    }

    /**
     * Grouped figures: by customer, entity, provider, category, module, channel or day.
     *
     * @return list<array<string, mixed>>
     */
    public function by(string $group): array
    {
        $column = match ($group) {
            'customer' => 'customer_id', 'entity' => 'entity_id', 'provider' => 'provider', 'category' => 'category',
            'module' => 'module', 'channel' => 'channel',
            'day' => "to_char(occurred_at at time zone 'Asia/Karachi', 'YYYY-MM-DD')",
            'month' => "to_char(occurred_at at time zone 'Asia/Karachi', 'YYYY-MM')",
        };   // fixed SQL, never typed in

        $rows = $this->read(fn () => $this->query()->selectRaw($column.' as k')
            ->selectRaw("count(*) filter (where channel = 'api') as api, coalesce(sum(quantity) filter (where channel = 'sms'), 0) as sms,
                coalesce(sum(quantity) filter (where channel = 'whatsapp'), 0) as whatsapp, coalesce(sum(quantity) filter (where channel = 'email'), 0) as email,
                coalesce(sum(quantity) filter (where channel = 'push'), 0) as push, count(*) as calls, count(*) filter (where not success) as failed,
                coalesce(sum(estimated_cost), 0) as estimated, coalesce(sum(charge), 0) as charged, coalesce(sum(actual_cost), 0) as actual")
            ->groupBy('k')->orderBy('k')->toBase()->get());

        $names = match ($group) {
            'customer' => $this->read(fn () => Customer::query()->pluck('name', 'id')->all()),
            'entity' => $this->read(fn () => Entity::query()->pluck('name', 'id')->all()),
            'category' => ServiceUsage::CATEGORIES,
            'channel' => ServiceUsage::CHANNELS,
            default => [],
        };

        return $rows->map(fn ($r) => ['key' => $r->k, 'name' => $r->k === null ? '(no company / platform)' : ($names[$r->k] ?? $r->k)] + (array) $r)
            ->sortByDesc(fn (array $r) => in_array($group, ['day', 'month'], true) ? $r['key'] : (float) $r['estimated'])->values()->all();
    }

    /** @return Collection<int, ServiceUsage> the records themselves, newest first */
    public function records(int $limit = 200): Collection
    {
        return $this->read(fn () => $this->query()->with(['account', 'entity', 'updates'])->latest('occurred_at')->limit($limit)->get());
    }

    /** The records and summaries as an Excel workbook. */
    public function excel(): string
    {
        $book = new XlsxWorkbook;
        $t = $this->totals();
        $book->addSheet('Summary', ['Figure', 'Value'], [['API calls', $t['api'] ?? 0], ['SMS', $t['sms'] ?? 0], ['WhatsApp', $t['whatsapp'] ?? 0],
            ['E-mails', $t['email'] ?? 0], ['Push', $t['push'] ?? 0], ['Successful', $t['ok'] ?? 0], ['Failed', $t['failed'] ?? 0],
            ['Estimated cost', $t['estimated'] ?? 0], ['Charged', $t['charged'] ?? 0], ['Actual (USD, as reported)', $t['actual_usd'] ?? 0]]);
        foreach (['customer' => 'By company', 'entity' => 'By mill', 'provider' => 'By provider', 'module' => 'By module', 'day' => 'By day'] as $g => $sheet) {
            if (! $this->platform && $g === 'customer') {
                continue;
            }
            $book->addSheet($sheet, ['Name', 'API', 'SMS', 'WhatsApp', 'E-mail', 'Push', 'Calls', 'Failed', 'Estimated', 'Charged', 'Actual'],
                array_map(fn (array $r) => [$r['name'], $r['api'], $r['sms'], $r['whatsapp'], $r['email'], $r['push'], $r['calls'], $r['failed'], $r['estimated'], $r['charged'], $r['actual']], $this->by($g)));
        }
        $book->addSheet('Records', ['Usage ID', 'When (PKT)', 'Company', 'Mill', 'Channel', 'Provider', 'Service', 'Module', 'Action', 'Why', 'By', 'Recipient',
            'Qty', 'Unit price', 'Estimated', 'Charged', 'Currency', 'Actual', 'Status', 'Billing'],
            $this->records(5000)->map(fn (ServiceUsage $u) => [$u->reference, $u->occurred_at->timezone('Asia/Karachi')->format('Y-m-d H:i:s'),
                $u->customer_id, $u->entity?->name, $u->channel, $u->provider, $u->service, $u->module, $u->action, $u->description, $u->byLabel(),
                $u->recipient_masked, $u->quantity, $u->unit_price, $u->estimated_cost, $u->charge, $u->currency, $u->actual_cost, $u->deliveryStatus(), $u->billing_status])->all());

        return $book->toString();
    }

    /**
     * A company's charge for a month: per channel and provider, the units, the free ones, the charge, plus each fixed
     * package's monthly fee, and tax. Issued once (kept as it was).
     */
    public static function issueStatement(string $customerId, CarbonImmutable $month, ?string $by): UsageStatement
    {
        return app(TenantContext::class)->runAsSystem(function () use ($customerId, $month, $by): UsageStatement {
            $existing = UsageStatement::query()->where('customer_id', $customerId)->whereDate('period', $month->startOfMonth()->toDateString())->first();
            if ($existing !== null) {
                return $existing;
            }
            $from = $month->startOfMonth()->shiftTimezone('Asia/Karachi')->utc();
            $to = $month->endOfMonth()->shiftTimezone('Asia/Karachi')->utc();
            $rows = ServiceUsage::query()->where('customer_id', $customerId)->whereBetween('occurred_at', [$from, $to])->where('success', true)
                ->selectRaw('channel, provider, price_id, currency, sum(quantity) as units, sum(charge) as charge, count(*) filter (where billing_status = \'free\') as free_calls')
                ->groupBy('channel', 'provider', 'price_id', 'currency')->toBase()->get();
            $lines = [];
            $subtotal = '0';
            $tax = '0';
            foreach ($rows as $r) {
                $price = $r->price_id ? ServicePrice::query()->find($r->price_id) : null;
                $lineTax = bcdiv(bcmul((string) $r->charge, (string) ($price->tax_percent ?? '0'), 6), '100', 6);
                $lines[] = ['channel' => $r->channel, 'provider' => $r->provider, 'units' => (int) $r->units, 'free_calls' => (int) $r->free_calls,
                    'charge' => round((float) $r->charge, 2), 'tax' => round((float) $lineTax, 2), 'currency' => $r->currency, 'model' => $price->model ?? null];
                $subtotal = bcadd($subtotal, (string) $r->charge, 6);
                $tax = bcadd($tax, $lineTax, 6);
            }
            // Fixed packages: their monthly fee, once each.
            $packages = ServicePrice::query()->where('model', 'fixed_package')->where('monthly_fee', '>', 0)
                ->whereDate('effective_from', '<=', $month->endOfMonth()->toDateString())
                ->where(fn ($q) => $q->where('customer_id', $customerId)->orWhereNull('customer_id'))->get()
                ->groupBy(fn (ServicePrice $p) => $p->channel.'|'.$p->provider)->map(fn ($g) => $g->sortByDesc('effective_from')->first());
            foreach ($packages as $p) {
                $fee = (string) $p->monthly_fee;
                $lineTax = bcdiv(bcmul($fee, (string) $p->tax_percent, 6), '100', 6);
                $lines[] = ['channel' => $p->channel, 'provider' => $p->provider, 'units' => null, 'free_calls' => null, 'charge' => (float) $fee,
                    'tax' => round((float) $lineTax, 2), 'currency' => $p->currency, 'model' => 'fixed_package'];
                $subtotal = bcadd($subtotal, $fee, 6);
                $tax = bcadd($tax, $lineTax, 6);
            }

            return UsageStatement::query()->create(['customer_id' => $customerId, 'period' => $month->startOfMonth()->toDateString(), 'lines' => $lines,
                'subtotal' => round((float) $subtotal, 2), 'tax' => round((float) $tax, 2), 'total' => round((float) bcadd($subtotal, $tax, 6), 2),
                'currency' => 'PKR', 'created_by' => $by]);
        });
    }

    /** @return list<array{provider: string, month: string, estimated: string, billed: string, difference: string}> estimated cost against what each provider billed */
    public static function reconciliation(int $months = 6): array
    {
        return app(TenantContext::class)->runAsSystem(function () use ($months): array {
            $since = CarbonImmutable::now('Asia/Karachi')->subMonthsNoOverflow($months - 1)->startOfMonth();
            $est = ServiceUsage::query()->where('occurred_at', '>=', $since->utc())
                ->selectRaw("provider, to_char(occurred_at at time zone 'Asia/Karachi', 'YYYY-MM') as month, sum(estimated_cost) as estimated")
                ->groupBy('provider', 'month')->toBase()->get()->keyBy(fn ($r) => $r->provider.'|'.$r->month);
            $billed = ProviderExpense::query()->whereDate('period', '>=', $since->toDateString())->get()
                ->groupBy(fn (ProviderExpense $e) => $e->provider.'|'.$e->period->format('Y-m'))->map(fn ($g) => (string) $g->sum('amount'));
            $keys = collect($est->keys())->merge($billed->keys())->unique()->sortDesc();

            return $keys->map(function (string $k) use ($est, $billed): array {
                [$provider, $month] = explode('|', $k, 2);
                $e = number_format((float) ($est[$k]->estimated ?? 0), 2, '.', '');
                $b = number_format((float) ($billed[$k] ?? 0), 2, '.', '');

                return ['provider' => $provider, 'month' => $month, 'estimated' => $e, 'billed' => $b, 'difference' => bcsub($b, $e, 2)];
            })->values()->all();
        });
    }
}
