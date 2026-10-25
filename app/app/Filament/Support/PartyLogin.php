<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Messaging\PartyAccounts;
use App\Domain\Messaging\PartyMessenger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\PartyMessage;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;

/**
 * "Party login" on a party: makes its own login (or a new password) and sends the details to the party's
 * mobile. The details are shown once to the person who made them; without a message gateway, a button
 * opens WhatsApp with the message ready to send.
 */
final class PartyLogin
{
    public static function action(): Action
    {
        return Action::make('partyLogin')
            ->label(fn (Party $record) => $record->login_account_id ? 'Party login: new password' : 'Make party login')
            ->icon('heroicon-o-key')->color('gray')
            ->visible(fn () => self::allowed())
            ->requiresConfirmation()
            ->modalDescription(fn (Party $record) => ($record->login_account_id
                ? 'Gives the party a new password (the old one stops working) and sends it to '
                : 'Makes a login for this party to see its own vehicles, bills, payments and balance, and sends the details to ')
                .($record->phone ?: 'its mobile number (add one first)').'.')
            ->action(fn (Party $record) => self::grant($record));
    }

    public static function allowed(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('messages.manage');
    }

    /** Makes the login and tells the person who made it what was sent (the password is shown only now). */
    public static function grant(Party $party): void
    {
        try {
            $result = app(PartyAccounts::class)->grant($party);
        } catch (BusinessRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $message = $result['message'];
        $how = match ($message?->status) {
            PartyMessage::SENT => 'Sent to the party by '.strtoupper($message->channel).'.',
            PartyMessage::MANUAL => 'No message gateway is set up: send it with the WhatsApp button below.',
            PartyMessage::FAILED => 'Sending failed ('.$message->error.'): send it with the WhatsApp button below.',
            PartyMessage::SKIPPED => 'Not sent: '.$message->error,
            default => 'Login messages are switched off (Masters → Party messages): give the details to the party yourself.',
        };
        $notification = Notification::make()->success()->persistent()
            ->title(($result['new'] ? 'Party login made' : 'New password given').': '.$result['username'])
            ->body('Password: '.$result['password'].' (shown only now; the party chooses its own at the first sign-in). '.$how);
        if ($message !== null && $message->status !== PartyMessage::SENT && $message->recipient !== '') {
            $notification->actions([NotificationAction::make('whatsapp')->label('Send on WhatsApp')->button()
                ->url(PartyMessenger::whatsappLink($message->recipient, $result['text']), shouldOpenInNewTab: true)]);
        }
        $notification->send();
    }
}
