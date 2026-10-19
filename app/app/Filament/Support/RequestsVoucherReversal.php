<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Approvals\Actions\VoucherReversal;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/** "Request reversal" button: a voucher is reversed only after a second person approves. */
final class RequestsVoucherReversal
{
    /** @param  callable(): (string|null)  $voucherId */
    public static function action(callable $voucherId): Action
    {
        return Action::make('requestReversal')->label('Request reversal')->icon('heroicon-o-arrow-uturn-left')->color('danger')
            ->visible(fn () => $voucherId() !== null && (Filament::auth()->user()?->can('voucher.reverse') ?? false))
            ->modalDescription('The voucher stays in the books; an opposite voucher is posted once a second person approves.')
            ->form([Textarea::make('reason')->required()->minLength(10)->maxLength(1000)])
            ->action(function (array $data) use ($voucherId): void {
                try {
                    $request = app(ApprovalService::class)->submit(VoucherReversal::KEY, ['voucher_id' => $voucherId(), 'reason' => $data['reason']], 'Reverse voucher', $data['reason']);
                } catch (ApprovalDenied|ValidationException $e) {
                    Notification::make()->danger()
                        ->title($e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage())->send();

                    return;
                }
                ApprovalNotice::send($request, 'Reversal sent for approval');
            });
    }
}
