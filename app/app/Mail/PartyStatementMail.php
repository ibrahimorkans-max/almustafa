<?php

declare(strict_types=1);

namespace App\Mail;

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
        return new Envelope(subject: $this->subjectLine);
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
