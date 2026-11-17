<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Messaging\SenderIdentities;
use App\Models\LoginAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The e-mail of a new user's first sign-in: where the sign-in came from, and the button that confirms the address. */
final class FirstSignInVerificationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly LoginAccount $account,
        public readonly string $url,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: SenderIdentities::mailFrom($this->account->entity_id),
            subject: __('Confirm your e-mail to finish signing in to :product', ['product' => (string) config('ricemill.product.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.first-sign-in', with: [
            'username' => $this->account->username,
            'info' => $this->account->first_sign_in ?? [],
            'at' => $this->account->first_sign_in_at?->timezone('Asia/Karachi')->format('d M Y, h:i A'),
            'url' => $this->url,
            'minutes' => $this->minutes,
            'product' => (string) config('ricemill.product.name'),
            'rtl' => \App\Support\Locales::isRtl(),
        ]);
    }
}
