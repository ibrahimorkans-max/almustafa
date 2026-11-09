<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\PaymentResource\Pages;

use App\Domain\Accounts\ManualVouchers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Accounts\PaymentResource;
use App\Filament\Support\Units;
use App\Models\Accounts\LedgerAccount;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/** Payments: money sent to a party (Request payment) and money received from one (Receive payment), any amount. */
class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Request payment'),
            Action::make('receive')->label('Receive payment')->icon('heroicon-o-arrow-down-left')->color('success')
                ->visible(fn () => Filament::auth()->user()?->can('ledger.post') ?? false)
                ->modalDescription('Money received from any party, into a cash or bank account, any amount. Posted as a receipt voucher (on the party\'s account).')
                ->form([
                    Select::make('party_id')->label('From party')->required()->searchable()
                        ->options(fn () => Party::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                    Select::make('into')->label('Into')->required()
                        ->options(fn () => LedgerAccount::query()->where('status', 'active')->whereIn('kind', ['cash', 'bank'])->orderBy('name')->get()
                            ->mapWithKeys(fn (LedgerAccount $l) => [$l->id => $l->displayName()])),
                    DatePicker::make('date')->default(now())->required(),
                    TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    Textarea::make('narration')->label('Narration (cheque / slip / transaction number)')->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $voucher = app(ManualVouchers::class)->receipt(Party::query()->findOrFail($data['party_id']), LedgerAccount::query()->findOrFail($data['into']),
                            Units::rupeesToPaise((string) $data['amount']), CarbonImmutable::parse($data['date'], 'Asia/Karachi'), $data['narration'] ?? null);
                        Notification::make()->success()->title('Received: receipt voucher '.$voucher->number.'.')->send();
                    } catch (BusinessRuleViolation $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
