<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Messaging\SenderIdentities;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A party statement by e-mail: the subject and message as written by the sender, the statement PDF attached. */
final class PartyStatementMail extends Mailable
{
    use Queueable;

    public function __construct(
        private readonly string $pdf,
        private readonly string $fileName,
        public readonly string $subjectLine,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        // From the mill (its e-mail sender, else its name), never the platform.
        return new Envelope(from: SenderIdentities::mailFrom(app(TenantContext::class)->entityId()), subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.party-statement', with: ['body' => $this->body]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->fileName)->withMime('application/pdf')];
    }
}
