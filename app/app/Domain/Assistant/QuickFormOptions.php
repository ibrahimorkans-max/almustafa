<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Filament\Support\QuickLabTest;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\Payment;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\DailyWages\DailyWageGroup;
use App\Models\Gate\GateEntry;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The data that fits each blank of a quick form (QuickForms), shown under the chat box while the cursor is on
 * that line: the vehicles waiting for the lab, the sellers, the paddy products, the expense names, the parties…
 * Pressing one writes it in the blank. Only the mill's own records (row-level security), active ones first.
 */
final class QuickFormOptions
{
    /** Choices shown for one blank (typing filters them). */
    private const LIMIT = 200;

    /**
     * The choices of every blank of a form, by the line's label as written in the message.
     *
     * @return array<string, list<array{label: string, value: string}>>
     */
    public static function for(string $key): array
    {
        $out = [];
        foreach (QuickForms::fields($key) as $name => [$label]) {
            try {
                $options = self::field($key, $name);
            } catch (Throwable $e) {
                report($e);
                $options = [];
            }
            if ($options !== []) {
                $out[$label] = array_slice($options, 0, self::LIMIT);
            }
        }

        return $out;
    }

    /** @return list<array{label: string, value: string}> */
    private static function field(string $key, string $name): array
    {
        return match ("{$key}.{$name}") {
            'party.acts_as' => self::words(['customer', 'vendor', 'farmer', 'broker', 'transporter', 'contractor', 'farmer, vendor']),
            'party.city' => self::plain(Party::query()->whereNotNull('city')->where('city', '!=', '')->groupBy('city')->orderByRaw('COUNT(*) DESC')->limit(30)->pluck('city')),
            'vehicle.registration_no' => self::plain(Vehicle::query()->where('status', 'active')->orderBy('registration_no')->pluck('registration_no')
                ->merge(GateEntry::query()->where('arrived_at', '>=', now()->subDays(60))->orderByDesc('arrived_at')->limit(100)->pluck('registration_no'))
                ->unique()),
            'vehicle.sauda' => self::saudas(),
            'vehicle.party' => self::plain(Party::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'vehicle.type' => [['label' => 'With Jack', 'value' => 'with jack'], ['label' => 'Without Jack', 'value' => 'without jack']],
            'vehicle.item' => self::plain(PaddyVariety::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'sauda.seller' => self::plain(Party::query()->where('status', 'active')->where(fn ($q) => $q->where('is_farmer', true)->orWhere('is_vendor', true))->orderBy('name')->pluck('name')),
            'sauda.product' => self::plain(Product::query()->where('category', 'paddy')->orderBy('name')->pluck('name')),
            'sauda.days' => self::words(['15', '30', '45', '60']),
            'expense.category' => self::plain(ExpenseCategory::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'expense.paid_from', 'wages.pay' => self::words($name === 'pay' ? ['cash', 'bank', 'later'] : ['cash', 'bank']),
            'payment.party' => self::plain(Party::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'payment.mode' => array_map(fn (string $k, string $label) => ['label' => $label, 'value' => $k], array_keys(Payment::MODES), Payment::MODES),
            'wages.work' => self::plain(DailyWageCategory::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'wages.group' => self::plain(DailyWageGroup::query()->where('status', 'active')->orderBy('name')->pluck('name')),
            'wages.workers' => self::words(['5', '10', '15', '20']),
            'lab.vehicle' => self::waitingForLab(),
            'lab.decision' => [['label' => 'Accept', 'value' => 'accept'], ['label' => 'Reject', 'value' => 'reject'], ['label' => 'Later (Lab Supervisor)', 'value' => 'later']],
            default => [],
        };
    }

    /** @return list<array{label: string, value: string}> confirmed purchase saudas: "0001 · Seller" → 0001 */
    private static function saudas(): array
    {
        return Sauda::query()->with('party')->where('direction', 'purchase')->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])
            ->whereNull('archived_at')->orderByDesc('created_at')->limit(60)->get()
            ->map(fn (Sauda $s) => ['label' => $s->number.($s->party !== null ? ' · '.$s->party->name : ''), 'value' => (string) $s->number])->values()->all();
    }

    /** @return list<array{label: string, value: string}> the vehicles waiting for the lab, by token: the full line shown, the plate written */
    private static function waitingForLab(): array
    {
        $labels = QuickLabTest::waiting();
        $plates = GateEntry::query()->whereIn('id', array_keys($labels))->pluck('registration_no', 'id');

        return array_values(array_map(fn (string $id, string $label) => ['label' => $label, 'value' => (string) ($plates[$id] ?? $label)],
            array_keys($labels), $labels));
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<array{label: string, value: string}>
     */
    private static function plain(Collection $values): array
    {
        return $values->filter(fn ($v) => filled($v))->map(fn ($v) => ['label' => (string) $v, 'value' => (string) $v])->values()->all();
    }

    /**
     * @param  list<string>  $words
     * @return list<array{label: string, value: string}>
     */
    private static function words(array $words): array
    {
        return array_map(fn (string $w) => ['label' => $w, 'value' => $w], $words);
    }
}
