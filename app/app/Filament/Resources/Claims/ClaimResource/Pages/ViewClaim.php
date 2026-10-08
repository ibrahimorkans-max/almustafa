<?php

declare(strict_types=1);

namespace App\Filament\Resources\Claims\ClaimResource\Pages;

use App\Domain\Claims\ClaimService;
use App\Filament\Resources\Claims\ClaimResource;
use App\Filament\Support\RequestsVoucherReversal;
use App\Filament\Support\RunsDomainActions;
use App\Filament\Support\Units;
use App\Models\Claims\Claim;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewClaim extends ViewRecord
{
    use RunsDomainActions;

    protected static string $resource = ClaimResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (string $p) => Filament::auth()->user()?->can($p) ?? false;
        $service = fn () => app(ClaimService::class);

        return [
            Action::make('approve')->label('Approve settlement')->icon('heroicon-o-check')->color('success')
                ->visible(fn (Claim $c) => $c->status === Claim::OPEN && $can('claim.approve'))
                ->modalDescription('Fix how much is settled (at most the amount claimed). Accounts then posts the credit or debit note.')
                ->form([
                    TextInput::make('amount')->label('Settle (₹)')->required()->regex(Units::DECIMAL_2)
                        ->default(fn (Claim $record) => bcdiv((string) $record->claimed_paise, '100', 2)),
                    Textarea::make('note')->maxLength(1000),
                ])
                ->action(fn (Claim $c, array $data) => $this->runDomain(fn () => $service()->approve($c, Units::rupeesToPaise($data['amount']), $data['note'] ?? null), 'Settlement approved')),
            Action::make('reject')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn (Claim $c) => $c->status === Claim::OPEN && $can('claim.approve'))
                ->form([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
                ->action(fn (Claim $c, array $data) => $this->runDomain(fn () => $service()->reject($c, $data['reason']), 'Claim rejected')),
            Action::make('settle')->label('Post note')->icon('heroicon-o-document-check')->color('primary')
                ->visible(fn (Claim $c) => $c->status === Claim::APPROVED && $can('ledger.post'))
                ->requiresConfirmation()
                ->modalDescription(fn (Claim $c) => $c->document_type === Claim::SALES
                    ? 'Posts a credit note to the buyer (GST reversed in the invoice proportion).'
                    : 'Posts a debit note to the supplier.')
                ->action(fn (Claim $c) => $this->runDomain(fn () => $service()->settle($c), 'Note posted')),
            ActionGroup::make([
                Action::make('note')->label('Add note')->icon('heroicon-o-chat-bubble-left')
                    ->visible(fn (Claim $c) => in_array($c->status, [Claim::OPEN, Claim::APPROVED], true) && $can('claim.manage'))
                    ->form([Textarea::make('text')->label('Note')->required()->maxLength(2000)])
                    ->action(fn (Claim $c, array $data) => $this->runDomain(fn () => $service()->note($c, $data['text']), 'Note added')),
                Action::make('evidence')->label('Add evidence')->icon('heroicon-o-paper-clip')
                    ->visible(fn (Claim $c) => $c->status === Claim::OPEN && $can('claim.manage'))
                    ->form([FileUpload::make('files')->multiple()->required()->storeFiles(false)->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120)])
                    ->action(fn (Claim $c, array $data) => $this->runDomain(fn () => $service()->addEvidence($c, CreateClaim::store((array) $data['files'])), 'Evidence added')),
                Action::make('withdraw')->color('gray')
                    ->visible(fn (Claim $c) => in_array($c->status, [Claim::OPEN, Claim::APPROVED], true) && $can('claim.manage'))
                    ->form([Textarea::make('reason')->required()->maxLength(1000)])
                    ->action(fn (Claim $c, array $data) => $this->runDomain(fn () => $service()->withdraw($c, $data['reason']), 'Claim withdrawn')),
                RequestsVoucherReversal::action(fn () => $this->record->getAttribute('status') === Claim::SETTLED ? $this->record->getAttribute('voucher_id') : null),
            ]),
        ];
    }
}
