<?php

declare(strict_types=1);

namespace App\Filament\Resources\Platform\SaasInvoiceResource\Pages;

use App\Domain\Saas\Billing;
use App\Filament\Resources\Platform\SaasInvoiceResource;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageSaasInvoices extends ManageRecords
{
    protected static string $resource = SaasInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issueMonth')->label('Issue this month\'s invoices')->icon('heroicon-o-document-duplicate')->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Every active or payment-due customer on a paid plan gets this month\'s invoice, unless it already has one. Trials are not billed.')
                ->action(function (): void {
                    $month = CarbonImmutable::now('Asia/Karachi');
                    $issued = 0;
                    foreach (Customer::query()->orderBy('code')->get() as $customer) {
                        $issued += app(Billing::class)->issueMonthly($customer, $month) !== null ? 1 : 0;
                    }
                    Notification::make()->success()->title("{$issued} invoices issued for ".$month->format('F Y').'.')->send();
                }),
            Action::make('newInvoice')->label('New invoice')->icon('heroicon-o-plus')
                ->form([
                    Select::make('customer_id')->label('Customer')->required()->searchable()
                        ->options(fn () => Customer::query()->orderBy('name')->get()->mapWithKeys(fn (Customer $c) => [$c->id => "{$c->name} ({$c->code})"])->all()),
                    TextInput::make('description')->required()->maxLength(200)->placeholder('Setup and training'),
                    TextInput::make('amount')->label('Amount (Rs)')->required()->regex(Units::DECIMAL_2),
                    TextInput::make('due_days')->label('Due in days')->integer()->minValue(0)->maxValue(90)
                        ->default((int) config('ricemill.saas.billing.due_days'))->required(),
                ])
                ->action(fn (array $data) => SaasInvoiceResource::run(function (LoginAccount $by) use ($data): void {
                    abort_unless($by->isPlatformAdmin(), 403);
                    app(Billing::class)->issue(Customer::query()->findOrFail($data['customer_id']), (string) $data['description'],
                        Units::rupeesToPaise((string) $data['amount']), dueDays: (int) $data['due_days']);
                }, 'Invoice issued.')),
        ];
    }
}
