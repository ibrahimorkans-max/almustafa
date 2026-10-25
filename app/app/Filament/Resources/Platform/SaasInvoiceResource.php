<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform;

use App\Domain\Saas\Billing;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Platform\SaasInvoiceResource\Pages;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use App\Models\SaasInvoice;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform admin's invoices to customers (SaaS billing): issue, record the money received (bank
 * transfer), cancel; see which customers reported a payment that still needs checking.
 */
class SaasInvoiceResource extends Resource
{
    protected static ?string $model = SaasInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-rupee';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $modelLabel = 'invoice';

    protected static ?string $slug = 'platform/invoices';

    protected static ?int $navigationSort = 15;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;   // issued through "New invoice" / "Issue this month's invoices"
    }

    public static function canEdit(Model $record): bool
    {
        return false;   // an invoice is never changed: it is paid or cancelled
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $reported = SaasInvoice::query()->where('status', SaasInvoice::OPEN)->whereNotNull('reported_at')->count();

        return $reported > 0 ? (string) $reported : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Payments reported, waiting to be checked';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('customer.name')->label('Customer')->description(fn (SaasInvoice $r) => $r->customer->code)->searchable(),
                TextColumn::make('description')->wrap(),
                TextColumn::make('amount_paise')->label('Amount')->formatStateUsing(fn (int $state) => Units::paiseToRupees($state))->alignEnd(),
                TextColumn::make('due_at')->label('Due')->date('d M Y', 'Asia/Karachi')->sortable(),
                TextColumn::make('standing')->label('State')->badge()->state(fn (SaasInvoice $r) => $r->standing())
                    ->formatStateUsing(fn (string $state, SaasInvoice $r) => match ($state) {
                        'overdue' => 'Overdue '.$r->daysOverdue().' d',
                        'paid' => 'Paid',
                        'void' => 'Cancelled',
                        default => 'Open',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success', 'overdue' => 'danger', 'void' => 'gray', default => 'warning'
                    }),
                // The transaction the customer reported: its ID (to find on the bank statement), how, how much, when.
                TextColumn::make('reported_reference')->label('Transaction ID (reported)')->fontFamily('mono')->weight('bold')->copyable()
                    ->searchable()
                    ->description(fn (SaasInvoice $r) => $r->reported_reference === null ? null : implode(' · ', array_filter([
                        $r->reportedMethod?->name,
                        $r->reported_amount_paise !== null ? Units::paiseToRupees($r->reported_amount_paise).($r->reported_amount_paise < $r->amount_paise ? ' (less than due)' : '') : null,
                        $r->reported_paid_on?->format('d M Y'),
                    ])))
                    ->placeholder('—')->toggleable(),
                TextColumn::make('payment_reference')->label('Received')->fontFamily('mono')->searchable()
                    ->description(fn (SaasInvoice $r) => $r->paid_at ? $r->paid_at->timezone('Asia/Karachi')->format('d M Y') : null)
                    ->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([SaasInvoice::OPEN => 'Open', SaasInvoice::PAID => 'Paid', SaasInvoice::VOID => 'Cancelled']),
                Filter::make('reported')->label('Payment reported, not yet checked')
                    ->query(fn (Builder $q) => $q->where('status', SaasInvoice::OPEN)->whereNotNull('reported_at')),
                Filter::make('overdue')->label('Overdue')->query(fn (Builder $q) => $q->where('status', SaasInvoice::OPEN)->where('due_at', '<', now())),
                SelectFilter::make('customer_id')->label('Customer')->relationship('customer', 'name')->searchable(),
            ])
            ->actions([
                Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')
                    ->url(fn (SaasInvoice $r) => route('filament.admin.saas.invoice', ['id' => $r->id]), shouldOpenInNewTab: true),
                ActionGroup::make([
                    Action::make('receipt')->label('Customer\'s receipt')->icon('heroicon-o-paper-clip')
                        ->visible(fn (SaasInvoice $r) => $r->receipt_path !== null)
                        ->url(fn (SaasInvoice $r) => route('filament.admin.saas.receipt', ['id' => $r->id]), shouldOpenInNewTab: true),
                    Action::make('markPaid')->label('Mark paid')->icon('heroicon-o-check-circle')->color('success')
                        ->visible(fn (SaasInvoice $r) => $r->status === SaasInvoice::OPEN)
                        ->modalDescription(fn (SaasInvoice $r) => $r->reported_reference !== null
                            ? 'The customer reported: '.$r->reportedSummary().'. Find this transaction ID on your statement first.'
                            : 'Record it once the payment has reached you.')
                        ->fillForm(fn (SaasInvoice $r) => [
                            'amount' => number_format(($r->reported_amount_paise ?? $r->amount_paise) / 100, 2, '.', ''),
                            'paid_on' => ($r->reported_paid_on ?? now('Asia/Karachi'))->format('Y-m-d'),
                            'reference' => $r->reported_reference,
                        ])
                        ->form([
                            TextInput::make('amount')->label('Amount received (Rs)')->required()->regex(Units::DECIMAL_2),
                            DatePicker::make('paid_on')->label('Received on')->required()->maxDate(now('Asia/Karachi')),
                            TextInput::make('reference')->label('Transaction ID (as on your statement)')->required()->maxLength(60)
                                ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: monospace;']),
                            Textarea::make('note')->maxLength(500),
                        ])
                        ->action(fn (SaasInvoice $r, array $data) => self::run(fn (LoginAccount $by) => app(Billing::class)
                            ->markPaid($r, $by, Units::rupeesToPaise((string) $data['amount']), (string) $data['paid_on'], (string) $data['reference'], $data['note'] ?? null), 'Payment recorded.')),
                    Action::make('void')->label('Cancel invoice')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (SaasInvoice $r) => $r->status === SaasInvoice::OPEN)
                        ->form([TextInput::make('reason')->required()->minLength(5)->maxLength(300)])
                        ->action(fn (SaasInvoice $r, array $data) => self::run(fn (LoginAccount $by) => app(Billing::class)->void($r, $by, (string) $data['reason']), 'Invoice cancelled.')),
                ]),
            ]);
    }

    /** @param  callable(LoginAccount): void  $work */
    public static function run(callable $work, string $done): void
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof LoginAccount, 403);
        try {
            $work($user);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($done)->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSaasInvoices::route('/')];
    }
}
