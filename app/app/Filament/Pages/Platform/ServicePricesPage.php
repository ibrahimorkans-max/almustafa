<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Saas\PlatformCompany;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Usage\UsageReport;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Usage\ProviderExpense;
use App\Models\Usage\ServicePrice;
use App\Models\Usage\ServiceUsage;
use App\Models\Usage\UsageStatement;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform → Service prices & billing (SaaS owner): the price list of every outside service (per provider and
 * service, from a date, for everyone or one company; free allowance, tiers, tax, monthly package fee, quota), what
 * the providers actually billed, the reconciliation of the two, and each company's monthly usage statement.
 * Prices are never edited: a new price from a new date replaces the old one, which stays for the usage it costed.
 */
class ServicePricesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Service prices & billing';

    protected static ?string $title = 'Service prices, provider bills & company billing';

    protected static ?string $slug = 'platform/service-prices';

    protected static ?int $navigationSort = 14;

    protected static string $view = 'filament.pages.platform.service-prices';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    /** @return array<string, string> */
    private static function companies(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => Customer::query()->whereNotIn('id', PlatformCompany::customerIds())->orderBy('name')->pluck('name', 'id')->all());
    }

    public function table(Table $table): Table
    {
        $companies = self::companies();

        return $table->query(fn (): Builder => ServicePrice::query()->latest('effective_from'))
            ->columns([
                TextColumn::make('channel')->formatStateUsing(fn (string $state) => ServiceUsage::CHANNELS[$state] ?? $state)->badge(),
                TextColumn::make('provider')->formatStateUsing(fn (string $state) => $state === '*' ? 'Any provider' : $state)->searchable(),
                TextColumn::make('service')->formatStateUsing(fn (string $state) => $state === '*' ? 'Any service' : $state),
                TextColumn::make('customer_id')->label('For')->formatStateUsing(fn (?string $state) => $state === null ? 'Every company' : ($companies[$state] ?? $state))->placeholder('Every company'),
                TextColumn::make('model')->formatStateUsing(fn (string $state) => ServicePrice::MODELS[$state] ?? $state),
                TextColumn::make('unit_price')->label('Cost / unit')->formatStateUsing(fn ($state, ServicePrice $p) => $p->currency.' '.rtrim(rtrim((string) $state, '0'), '.')),
                TextColumn::make('charge_price')->label('Charge / unit')->formatStateUsing(fn ($state, ServicePrice $p) => $p->currency.' '.rtrim(rtrim((string) $state, '0'), '.'))->placeholder('as cost'),
                TextColumn::make('free_allowance')->label('Free / month'),
                TextColumn::make('monthly_fee')->label('Monthly fee'),
                TextColumn::make('tax_percent')->label('Tax %'),
                TextColumn::make('monthly_quota')->label('Quota')->placeholder('—'),
                TextColumn::make('effective_from')->label('From')->date('d M Y'),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No prices yet')->emptyStateDescription('Without a price, usage is recorded with no cost. Add the price of each service you pay for.');
    }

    public function addPriceAction(): Action
    {
        return Action::make('addPrice')->label('Add a price')->icon('heroicon-o-plus')
            ->modalDescription('A new price from its date; the earlier price stays for the usage before it. TOTAL = QUANTITY × UNIT PRICE.')
            ->form([
                Grid::make(3)->schema([
                    Select::make('channel')->options(ServiceUsage::CHANNELS)->required(),
                    TextInput::make('provider')->label('Provider (* = any)')->default('*')->required()->maxLength(60)
                        ->datalist(['*', 'OpenRouter', 'OpenAI', 'Google Gemini', 'WhatsApp Cloud (Meta)', 'Firebase Cloud Messaging', 'Gmail API', 'Microsoft Graph', 'Twilio']),
                    TextInput::make('service')->label('Service (* = any)')->default('*')->required()->maxLength(80),
                    Select::make('customer_id')->label('For')->options(self::companies())->placeholder('Every company')->searchable(),
                    Select::make('model')->label('Pricing')->options(ServicePrice::MODELS)->default('pay_per_use')->required(),
                    DatePicker::make('effective_from')->label('From')->default(now('Asia/Karachi')->toDateString())->required(),
                    TextInput::make('unit_price')->label('Cost per unit')->numeric()->minValue(0)->required(),
                    TextInput::make('charge_price')->label('Charged to the company per unit (empty: same)')->numeric()->minValue(0),
                    Select::make('currency')->options(['PKR' => 'PKR', 'USD' => 'USD'])->default('PKR')->required(),
                    TextInput::make('free_allowance')->label('Free units a month')->integer()->minValue(0)->default(0),
                    TextInput::make('monthly_fee')->label('Monthly fee (package)')->numeric()->minValue(0)->default(0),
                    TextInput::make('tax_percent')->label('Tax %')->numeric()->minValue(0)->maxValue(100)->default(0),
                    TextInput::make('monthly_quota')->label('Monthly quota (alert above)')->integer()->minValue(1),
                    TextInput::make('note')->maxLength(200)->columnSpan(2),
                ]),
                Repeater::make('tiers')->label('Tiers (a lower price from the n-th unit of the month)')->defaultItems(0)->columns(2)->schema([
                    TextInput::make('from')->label('From unit')->integer()->minValue(1)->required(),
                    TextInput::make('price')->label('Unit price')->numeric()->minValue(0)->required(),
                ]),
            ])
            ->action(function (array $data): void {
                $price = ServicePrice::query()->create(array_intersect_key($data, array_flip(['channel', 'provider', 'service', 'customer_id', 'model', 'effective_from',
                    'unit_price', 'charge_price', 'currency', 'free_allowance', 'monthly_fee', 'tax_percent', 'monthly_quota', 'note'])) + [
                        'tiers' => array_values(array_map(fn (array $t) => ['from' => (int) $t['from'], 'price' => (string) $t['price']], (array) ($data['tiers'] ?? []))) ?: null,
                        'free_allowance' => (int) ($data['free_allowance'] ?? 0), 'monthly_fee' => $data['monthly_fee'] ?? 0, 'tax_percent' => $data['tax_percent'] ?? 0,
                        'created_by' => Filament::auth()->id(),
                    ]);
                app(AuditLogger::class)->record('usage.price_added', $price, null, $price->toArray());
                Notification::make()->success()->title('Price added.')->send();
            });
    }

    public function providerBillAction(): Action
    {
        return Action::make('providerBill')->label("Record a provider's bill")->icon('heroicon-o-document-text')->color('gray')
            ->form([
                Grid::make(2)->schema([
                    TextInput::make('provider')->required()->maxLength(60),
                    Select::make('channel')->options(ServiceUsage::CHANNELS),
                    DatePicker::make('period')->label('Month billed (any day of it)')->required(),
                    TextInput::make('amount')->numeric()->minValue(0)->required(),
                    Select::make('currency')->options(['PKR' => 'PKR', 'USD' => 'USD'])->default('PKR')->required(),
                    TextInput::make('reference')->label('Invoice number')->maxLength(120),
                ]),
            ])
            ->action(function (array $data): void {
                $e = ProviderExpense::query()->create(['provider' => $data['provider'], 'channel' => $data['channel'] ?? null,
                    'period' => CarbonImmutable::parse($data['period'])->startOfMonth()->toDateString(), 'amount' => $data['amount'], 'currency' => $data['currency'],
                    'reference' => $data['reference'] ?? null, 'created_by' => Filament::auth()->id()]);
                app(AuditLogger::class)->record('usage.provider_bill', $e, null, $e->toArray());
                Notification::make()->success()->title("Provider's bill recorded.")->send();
            });
    }

    public function statementsAction(): Action
    {
        return Action::make('statements')->label('Issue monthly statements')->icon('heroicon-o-banknotes')->color('gray')
            ->modalDescription('Works out each company\'s usage charge for the month (units beyond the free allowance × the charge price, package fees, tax). A statement once issued is kept as it is.')
            ->form([DatePicker::make('month')->label('Month (any day of it)')->default(now('Asia/Karachi')->subMonthNoOverflow()->toDateString())->required()])
            ->action(function (array $data): void {
                $month = CarbonImmutable::parse($data['month'], 'Asia/Karachi');
                $n = 0;
                foreach (array_keys(self::companies()) as $id) {
                    UsageReport::issueStatement($id, $month, Filament::auth()->id());
                    $n++;
                }
                app(AuditLogger::class)->record('usage.statements_issued', null, null, null, ['month' => $month->format('Y-m'), 'companies' => $n]);
                Notification::make()->success()->title("Statements for {$month->format('M Y')}: {$n} companies.")->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->addPriceAction(), $this->providerBillAction(), $this->statementsAction()];
    }

    /** @return list<UsageStatement> */
    public function statements(): array
    {
        return app(TenantContext::class)->runAsSystem(fn () => UsageStatement::query()->latest('period')->limit(60)->get()->all());
    }

    /** @return array<string, string> */
    public function companyNames(): array
    {
        return self::companies();
    }
}
