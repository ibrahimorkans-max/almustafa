<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Documents\PdfRenderer;
use App\Domain\Tenancy\TenantContext;
use App\Models\Documents\GeneratedDocument;
use App\Models\Entity;
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
        return new Envelope(subject: $this->brand()['name'].' — '.$this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.document', with: [
            'title' => $this->title, 'mill' => $this->brand()['name'], 'phone' => $this->brand()['phone'],
            'number' => $this->document->displayNumber(), 'code' => $this->document->verify_code, 'verify' => url('/verify'),
        ]);
    }

    /**
     * The issuing mill's own name and phone (each customer's mills sign as themselves).
     *
     * @return array<string, mixed>
     */
    private function brand(): array
    {
        return PdfRenderer::brand(app(TenantContext::class)->runAsSystem(fn () => Entity::query()->find($this->document->entity_id)));
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $name = preg_replace('/[^A-Za-z0-9-]+/', '-', $this->document->displayNumber()).'.pdf';

        return [Attachment::fromData(fn () => $this->pdf, $name)->withMime('application/pdf')];
    }
}
