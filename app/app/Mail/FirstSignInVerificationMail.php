<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Auth\EmailCode;
use App\Domain\Messaging\SenderIdentities;
use App\Filament\Pages\FirstSignInPage;
use App\Filament\Pages\NewNetworkPage;
use App\Filament\Pages\SecurityEmailPage;
use App\Models\LoginAccount;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit code of a check on one sign-in (App\Domain\Auth\EmailCode): a new person's first sign-in, or a sign-in
 * from a new network. Says where the sign-in came from; the code is typed on that screen.
 */
final class FirstSignInVerificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly LoginAccount $account,
        public readonly string $code,
        public readonly int $minutes,
        public readonly string $purpose = EmailCode::FIRST_SIGN_IN,
        /** @var array<string, mixed> where this sign-in came from (a new network's) */
        public readonly array $signInInfo = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: SenderIdentities::mailFrom($this->account->entity_id),
            subject: __(':code is your :product sign-in code', ['code' => $this->code, 'product' => (string) config('ricemill.product.name')]));
    }

    public function content(): Content
    {
        $first = $this->purpose === EmailCode::FIRST_SIGN_IN;
        $user = ['username' => $this->account->username];

        return new Content(view: 'mail.first-sign-in', with: [
            'username' => $this->account->username,
            'intro' => match ($this->purpose) {
                EmailCode::NEW_NETWORK => __('Someone signed in as :username from a network not used before. If it was you, type this code on the screen where you are signing in:', $user),
                EmailCode::NEW_DEVICE => __('Someone signed in as :username on a device not used before. If it was you, type this code on that device to approve it:', $user),
                EmailCode::PASSWORD_RESET => __('Someone asked to reset the password of :username. If it was you, type this code on the password reset screen:', $user),
                EmailCode::CONFIRM_EMAIL => __(':username asked to use this address for security codes. If it was you, type this code on the screen that asked for it:', $user),
                default => __('Someone signed in for the first time as :username. If it was you, type this code on the screen where you are signing in:', $user),
            },
            'info' => $first ? ($this->account->first_sign_in ?? []) : $this->signInInfo,
            'at' => $first ? $this->account->first_sign_in_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') : now()->timezone('Asia/Karachi')->format('d M Y, h:i A'),
            'code' => $this->code,
            'url' => match ($this->purpose) {
                EmailCode::NEW_NETWORK, EmailCode::NEW_DEVICE => NewNetworkPage::getUrl(panel: 'admin'),
                EmailCode::PASSWORD_RESET => route('filament.admin.auth.forgot-password'),
                EmailCode::CONFIRM_EMAIL => SecurityEmailPage::getUrl(panel: 'admin'),
                default => FirstSignInPage::getUrl(panel: 'admin'),
            },
            'minutes' => $this->minutes,
            'product' => (string) config('ricemill.product.name'),
            'rtl' => Locales::isRtl(),
        ]);
    }
}
