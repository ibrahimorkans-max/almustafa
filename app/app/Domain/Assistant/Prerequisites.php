<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Filament\Resources\Accounts\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\Accounts\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\Accounts\RoznamchaResource\Pages\CreateRoznamchaPage;
use App\Filament\Resources\DailyWages\DailyWageSheetResource\Pages\CreateDailyWageSheet;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Filament\Resources\Gate\GateEntryResource\Pages\CreateGateEntry;
use App\Filament\Resources\Hr\AdvanceResource\Pages\CreateAdvance;
use App\Filament\Resources\Hr\EmployeeResource;
use App\Filament\Resources\Hr\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Resources\Inventory\RequisitionResource\Pages\CreateRequisition;
use App\Filament\Resources\Masters\GodownResource;
use App\Filament\Resources\Masters\PartyResource;
use App\Filament\Resources\Masters\PartyResource\Pages\CreateParty;
use App\Filament\Resources\Masters\ProductResource;
use App\Filament\Resources\Procurement\SaudaResource;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Filament\Resources\Production\ProductionBatchResource\Pages\CreateProductionBatch;
use App\Filament\Resources\Sales\DispatchOrderResource\Pages\CreateDispatchOrder;
use App\Filament\Resources\Sales\SalesSaudaResource;
use App\Filament\Resources\Sales\SalesSaudaResource\Pages\CreateSalesSauda;
use App\Filament\Support\QuickLabTest;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\LedgerAccount;
use App\Models\DailyWages\DailyWageCategory;
use App\Models\Gate\LabParameter;
use App\Models\Gate\Scale;
use App\Models\Hr\Employee;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use Filament\Resources\Resource;
use Throwable;

/**
 * What must exist before a new record can be made, shown before it is made: on the "new" form of a screen
 * (a box at the top) and in the assistant's quick buttons. Each check is
 *  - "ok"       ready (e.g. "12 sellers");
 *  - "missing"  needed first, with a link to add it (the record cannot be saved without it);
 *  - "info"     what to have at hand (a phone number, the CNIC…).
 */
final class Prerequisites
{
    /** The "new" pages that show the box, and the record each makes. */
    public const PAGES = [
        CreateParty::class => 'party', CreateSauda::class => 'sauda', CreateSalesSauda::class => 'sales_sauda',
        CreateDispatchOrder::class => 'dispatch_order', CreateGateEntry::class => 'vehicle', CreatePayment::class => 'payment',
        CreateExpense::class => 'expense', CreateDailyWageSheet::class => 'wages', CreateAdvance::class => 'advance',
        CreateRequisition::class => 'requisition', CreateProductionBatch::class => 'production', CreateEmployee::class => 'employee',
        CreateRoznamchaPage::class => 'roznamcha',
    ];

    /** @return list<array{state: 'ok'|'missing'|'info', text: string, url?: string|null, link?: string|null}> */
    public static function for(string $record): array
    {
        try {
            return match ($record) {
                'party' => [
                    self::info('The code is given automatically.'),
                    self::info('Have the name and what the party is (customer, vendor, farmer, broker, transporter or labour contractor).'),
                    self::info('A mobile number lets the party get WhatsApp messages; CNIC / NTN / STRN are needed for withholding and sales tax.'),
                ],
                'sauda' => [
                    self::count(Party::query()->where('status', 'active')->where(fn ($q) => $q->where('is_farmer', true)->orWhere('is_vendor', true))->count(),
                        'seller (farmer or vendor)', 'sellers', 'Add a party that acts as farmer or vendor', self::add(PartyResource::class)),
                    self::count(Product::query()->where('category', 'paddy')->count(), 'paddy product', 'paddy products', 'Add a paddy product',
                        self::add(ProductResource::class)),
                    self::info('Have the quantity (maund), the rate per maund and the delivery days.'),
                ],
                'sales_sauda' => [
                    self::count(Party::query()->where('status', 'active')->where('is_customer', true)->count(), 'buyer (customer)', 'buyers',
                        'Add a party that acts as customer', self::add(PartyResource::class)),
                    self::count(Product::query()->whereIn('category', ['rice', 'by_product'])->count(), 'rice or by-product', 'rice and by-products',
                        'Add the product you sell', self::add(ProductResource::class)),
                ],
                'dispatch_order' => [
                    self::count(Sauda::query()->where('direction', 'sale')->whereIn('status', [Sauda::STATUS_CONFIRMED, Sauda::STATUS_IN_DELIVERY])->count(), 'confirmed sales sauda', 'confirmed sales saudas',
                        'Make and confirm a sales sauda first', self::add(SalesSaudaResource::class)),
                    self::count(Godown::query()->count(), 'godown', 'godowns', 'Add the godown it loads from', self::add(GodownResource::class)),
                ],
                'vehicle' => [
                    self::info('Have the number plate; the driver and the bags if known.'),
                    self::count(Sauda::query()->where('direction', 'purchase')->where('status', Sauda::STATUS_CONFIRMED)->count(), 'confirmed purchase sauda', 'confirmed purchase saudas',
                        'Paddy is usually delivered against a confirmed sauda (a visit needs none)', self::add(SaudaResource::class), need: false),
                    self::count(Scale::query()->count(), 'weighbridge', 'weighbridges', 'Connect a weighbridge to weigh it', null, need: false),
                ],
                'payment' => [
                    self::count(Party::query()->where('status', 'active')->count(), 'party', 'parties', 'Add the party to pay', self::add(PartyResource::class)),
                    self::count(LedgerAccount::query()->whereIn('kind', ['bank', 'cash'])->where('status', 'active')->count(), 'bank or cash account', 'bank and cash accounts',
                        'Add the bank account it is paid from', null),
                    self::info('Payments are released after approval (the Owner\'s at once).'),
                ],
                'expense' => [
                    self::count(self::expenseCategories(), 'expense category', 'expense categories', 'The standard categories are added when you open the form', null, need: false),
                    self::info('Have the amount, what it was for, and whether it was paid in cash or from the bank.'),
                ],
                'wages' => [
                    self::count(DailyWageCategory::query()->count(), 'kind of work (with its rate)', 'kinds of work', 'Add the kind of work and its rate', null),
                    self::info('Have the number of workers and the days (or bags / maund for piece work).'),
                ],
                'advance' => [
                    self::count(Employee::query()->where('status', Employee::ACTIVE)->count(), 'active employee', 'active employees', 'Add the employee first',
                        self::add(EmployeeResource::class)),
                ],
                'requisition' => [
                    self::count(Product::query()->count(), 'product', 'products', 'Add the product', self::add(ProductResource::class)),
                    self::count(Godown::query()->count(), 'godown', 'godowns', 'Add the godown it comes from', self::add(GodownResource::class)),
                ],
                'production' => [
                    self::count(Product::query()->where('category', 'paddy')->count(), 'paddy product', 'paddy products', 'Add the paddy variety', self::add(ProductResource::class)),
                    self::count(Product::query()->where('category', 'rice')->count(), 'rice product', 'rice products', 'Add the rice it makes', self::add(ProductResource::class)),
                ],
                'employee' => [
                    self::info('Have the name, CNIC, joining date, department / designation and the salary.'),
                ],
                'lab' => [
                    self::count(count(QuickLabTest::waiting()), 'vehicle waiting for the lab', 'vehicles waiting for the lab',
                        'Register the vehicle\'s arrival at the gate first', self::add(GateEntryResource::class)),
                    self::count(LabParameter::query()->where('status', 'active')->count(), 'lab test (moisture, broken…)', 'lab tests with their limits',
                        'Set the lab tests and their limits (Gate → Lab limits)', null),
                    self::info('Type each result in percent; leave a test blank if not done. Above its grace limit, the lot is rejected automatically.'),
                ],
                'roznamcha' => [
                    self::count(LedgerAccount::query()->where('kind', 'cash')->where('status', 'active')->count(), 'cash account', 'cash accounts', 'A cash account is needed', null),
                    self::info('A clear photo of the whole page, flat and in good light, reads best (Urdu or English).'),
                ],
                default => [],
            };
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * True when nothing needed is missing.
     *
     * @param  list<array{state: string, text: string, url?: string|null, link?: string|null}>  $checks
     */
    public static function ready(array $checks): bool
    {
        return ! in_array('missing', array_column($checks, 'state'), true);
    }

    /**
     * The checks as text lines, for the assistant: "✓ 12 sellers ready", "✗ No paddy product yet: Add a paddy product.".
     *
     * @param  list<array{state: string, text: string, url?: string|null, link?: string|null}>  $checks
     */
    public static function text(array $checks): string
    {
        return implode("\n", array_map(fn (array $c) => match ($c['state']) {
            'ok' => '✓ '.$c['text'],
            'missing' => '✗ '.$c['text'],
            default => '• '.$c['text'],
        }, $checks));
    }

    /**
     * Links to add what is missing.
     *
     * @param  list<array{state: string, text: string, url?: string|null, link?: string|null}>  $checks
     * @return list<array{label: string, url: string}>
     */
    public static function links(array $checks): array
    {
        return array_values(array_map(fn (array $c) => ['label' => (string) $c['link'], 'url' => (string) $c['url']],
            array_filter($checks, fn (array $c) => $c['state'] === 'missing' && filled($c['url'] ?? null) && filled($c['link'] ?? null))));
    }

    /** @return array{state: 'ok'|'missing'|'info', text: string, url: string|null, link: string|null} */
    private static function count(int $n, string $one, string $many, string $toAdd, ?string $url, bool $need = true): array
    {
        if ($n > 0) {
            return ['state' => 'ok', 'text' => $n.' '.($n === 1 ? $one : $many).' ready', 'url' => null, 'link' => null];
        }

        return ['state' => $need ? 'missing' : 'info', 'text' => 'No '.$one.' yet: '.$toAdd.'.', 'url' => $url, 'link' => $toAdd];
    }

    /** @return array{state: 'info', text: string} */
    private static function info(string $text): array
    {
        return ['state' => 'info', 'text' => $text];
    }

    /**
     * Where to add one: the screen's "new" page, or its list (where "New" opens in a window); none when this person
     * may not add them.
     *
     * @param  class-string<resource>  $resource
     */
    private static function add(string $resource): ?string
    {
        try {
            if (! $resource::canCreate()) {
                return null;
            }

            return $resource::hasPage('create') ? $resource::getUrl('create') : $resource::getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private static function expenseCategories(): int
    {
        return ExpenseCategory::query()->where('status', 'active')->count();
    }
}
