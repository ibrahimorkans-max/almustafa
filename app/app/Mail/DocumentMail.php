<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Documents\GeneratedDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An HR document by e-mail: the original PDF attached, with the verification details in the body. */
final class DocumentMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly GeneratedDocument $document,
        private readonly string $pdf,
        public readonly string $title,
        public readonly string $note,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('ricemill.brand.name').' — '.$this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.document', with: [
            'title' => $this->title, 'mill' => config('ricemill.brand.name'), 'phone' => config('ricemill.brand.phone'),
            'number' => $this->document->displayNumber(), 'code' => $this->document->verify_code, 'verify' => url('/verify'),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', $this->document->displayNumber()).'.pdf';

        return [Attachment::fromData(fn () => $this->pdf, $name)->withMime('application/pdf')];
    }
}
