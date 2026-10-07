<?php

declare(strict_types=1);

namespace App\Filament\Resources\Masters\PartyResource\RelationManagers;

use App\Domain\Approvals\Actions\PartyBankAccountChange;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Filament\Resources\ApprovalRequestResource;
use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Bank accounts are read-only here; "Request …" actions file maker-checker requests.
 */
class BankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'bankAccounts';

    protected static ?string $title = 'Bank accounts';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_holder'),
                TextColumn::make('account_last4')->label('Account')->formatStateUsing(fn (string $state) => 'XXXX'.$state)->fontFamily('mono'),
                TextColumn::make('ifsc')->label('IFSC')->fontFamily('mono'),
                TextColumn::make('bank_name')->placeholder('—'),
                IconColumn::make('is_primary')->label('Primary')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                $this->requestAction('add', 'Request new bank account')->icon('heroicon-o-plus'),
            ])
            ->actions([
                $this->requestAction('update', 'Request change')->visible(fn (PartyBankAccount $r) => $r->status === 'active'),
                $this->requestAction('deactivate', 'Request deactivation')->color('danger')->visible(fn (PartyBankAccount $r) => $r->status === 'active'),
            ]);
    }

    private function requestAction(string $op, string $label): Action
    {
        $details = $op === 'deactivate' ? [] : [
            TextInput::make('account_holder')->required()->maxLength(160),
            TextInput::make('account_number')->label('Account number')->required()->regex('/^\d{9,18}$/')->password()->revealable(),
            TextInput::make('account_number_confirmation')->label('Repeat account number')->required()->same('account_number')->password(),
            TextInput::make('ifsc')->label('IFSC')->required()->maxLength(11),
            TextInput::make('bank_name')->maxLength(120),
            TextInput::make('branch')->maxLength(120),
            TextInput::make('upi_id')->label('UPI ID')->maxLength(100),
            Toggle::make('is_primary')->label('Primary account'),
        ];

        return Action::make('request_'.$op)
            ->label($label)
            ->visible(fn () => Filament::auth()->user()?->can('master.manage') ?? false)
            ->modalDescription('Takes effect only after approval by a different person who may view bank details.')
            ->form([...$details, Textarea::make('reason')->required()->maxLength(1000)])
            ->action(function (array $data, ?PartyBankAccount $record) use ($op): void {
                /** @var Party $party */
                $party = $this->getOwnerRecord();
                $payload = array_intersect_key($data, array_flip(['account_holder', 'account_number', 'ifsc', 'bank_name', 'branch', 'upi_id', 'is_primary']))
                    + ['op' => $op, 'party_id' => $party->id, 'bank_account_id' => $record?->id];
                if ($op === 'add') {
                    unset($payload['bank_account_id']);
                }

                try {
                    $request = app(ApprovalService::class)->submit(
                        PartyBankAccountChange::KEY,
                        $payload,
                        ucfirst($op).' bank account for '.$party->name.($record ? ' (XXXX'.$record->account_last4.')' : ''),
                        $data['reason'],
                    );
                } catch (ApprovalDenied|ValidationException $e) {
                    $message = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
                    Notification::make()->title('Request not submitted')->body($message)->danger()->send();

                    return;
                }

                Notification::make()->title('Sent for approval')->success()
                    ->actions([NotificationAction::make('open')->url(ApprovalRequestResource::getUrl('view', ['record' => $request]))])
                    ->send();
            });
    }
}
