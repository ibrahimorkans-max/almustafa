<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Accounts\AccountReports;
use App\Domain\Gate\GateService;
use App\Filament\Pages\AllReportsPage;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Procurement this month, from approved figures only (posted bills, released payments, approved
 * settlements): purchase value, suppliers owed, payments, negotiated reduction, vehicles received,
 * lab rejections, total Caat and settlements still waiting.
 */
class ProcurementOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '120s';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('dashboard.view')
            && ($user->hasPermission('ledger.view') || $user->hasPermission('purchase.approve'));
    }

    protected function getStats(): array
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();
        $now = CarbonImmutable::now('Asia/Karachi');
        [$from, $to] = [$now->startOfMonth(), $now];
        $start = $from->startOfDay()->utc();
        $end = $to->endOfDay()->utc();
        $rs = fn (int $p) => Money::paise($p)->format();

        $bills = PurchaseBill::query()->where('status', PurchaseBill::POSTED)->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()]);
        $purchase = (int) (clone $bills)->sum('net_paise');
        $paid = (int) Payment::query()->where('status', Payment::APPROVED)->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])->sum('amount_paise');
        $owed = $me->hasPermission('ledger.view')
            ? array_sum(array_column(app(AccountReports::class)->payablesAgeing($to), 'outstanding')) : null;

        $entries = GateEntry::query()->where('direction', 'inbound')->whereNull('archived_at')->whereBetween('arrived_at', [$start, $end]);
        $received = (clone $entries)->whereIn('status', ['slip_issued', 'exited'])->count();
        $rejections = GateEvent::query()->where('event', 'lab_rejected')->whereBetween('recorded_at', [$start, $end])->distinct('gate_entry_id')->count('gate_entry_id');
        $gate = app(GateService::class);
        $caat = '0';
        $reduction = 0;
        $pending = 0;
        foreach ((clone $entries)->get(['id', 'sauda_id', 'tare_kg', 'status']) as $e) {
            if ($gate->settlementApproved($e)) {
                $s = (array) GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'settlement_approved')->newestFirst()->value('data');
                $caat = bcadd($caat, (string) ($s['total_caat_kg'] ?? '0'), 3);
                $offered = $e->sauda_id !== null ? (int) Sauda::query()->whereKey($e->sauda_id)->value('rate_paise_per_maund') : null;
                if ($offered !== null && isset($s['price_paise_per_maund'])) {
                    $reduction += (int) round((float) bcmul(bcdiv((string) $s['payable_kg'], '40', 4), (string) ($offered - (int) $s['price_paise_per_maund']), 2));
                }
            } elseif ($e->tare_kg !== null && ! in_array($e->status, ['exited', 'cancelled'], true)) {
                $pending++;
            }
        }

        return array_values(array_filter([
            Stat::make('Purchase value (month)', $rs($purchase))->description('Posted purchase bills')->url(AllReportsPage::getUrl()),
            $owed !== null ? Stat::make('Suppliers owed', $rs((int) $owed))->description('Outstanding purchase bills') : null,
            Stat::make('Payments made (month)', $rs($paid)),
            Stat::make('Negotiated reduction (month)', $rs($reduction))->description('Offered price less accepted, on settled lots'),
            Stat::make('Vehicles received (month)', (string) $received)->url(GateEntryResource::getUrl('index')),
            Stat::make('Lab rejections (month)', (string) $rejections)->color($rejections > 0 ? 'warning' : 'gray'),
            Stat::make('Total Caat (month)', number_format((float) $caat, 0).' kg'),
            Stat::make('Settlements waiting', (string) $pending)->color($pending > 0 ? 'warning' : 'success')
                ->description('Weighed empty, Caat and price not approved yet'),
        ]));
    }
}
