<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Messaging\Gateways\WhatsAppCloud;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Mail\PartyStatementMail;
use App\Models\Accounts\StatementShare;
use App\Models\Entity;
use App\Models\Masters\Party;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prints and shares a party statement, and keeps the history (StatementShare):
 *
 *  pdf       the statement as a PDF (A4 portrait, wide tables shrunk to fit).
 *  link      a secure link to the PDF: signed, expiring (3 days by default), naming no figures in the address.
 *  whatsapp  through the mill's WhatsApp Business connection when set up (recorded as handed to WhatsApp:
 *            WhatsApp does not tell us here that it was delivered or read); otherwise a wa.me link with the
 *            message and the secure link, for the person to send from their own phone (recorded as prepared).
 *  email     to the address given, the subject and message as written, the PDF attached (recorded as
 *            handed to the mail server).
 */
final class PartyStatementSharing
{
    public const LINK_HOURS = 72;

    public function __construct(
        private readonly PartyStatementReport $report,
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public function pdf(Party $party, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): string
    {
        $statement = $this->report->build($party, $from, $to, $filters);
        $entity = $this->tenant->runAsSystem(fn () => Entity::query()->find($party->entity_id));

        return $this->pdf->render('reports.party-statement', [
            'title' => 'Statement '.$party->name, 's' => $statement, 'view' => (string) ($filters['view'] ?? 'detailed'),
        ], $entity, 'A4');
    }

    public static function fileName(Party $party, CarbonImmutable $to): string
    {
        return 'statement-'.Str::slug($party->code ?: $party->name).'-'.$to->toDateString().'.pdf';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{url: string, expires: CarbonImmutable}
     */
    public function link(Party $party, CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $hours = self::LINK_HOURS): array
    {
        $expires = CarbonImmutable::now()->addHours(max(1, min($hours, 24 * 30)));
        $url = URL::temporarySignedRoute('statements.shared', $expires, [
            'party' => $party->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'f' => base64_encode((string) json_encode(self::cleanFilters($filters))),
        ]);

        return ['url' => $url, 'expires' => $expires];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{status: string, url: string|null, detail: string}
     */
    public function whatsapp(Party $party, string $phone, string $message, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $to164 = PakistanIds::normalizeMobile($phone) ?? throw new BusinessRuleViolation('Give a mobile number such as 0300 1234567.');
        $link = $this->link($party, $from, $to, $filters);
        $text = trim($message)."\n\n".$link['url'];
        $entityId = $party->entity_id;
        if (WhatsAppCloud::configured($entityId)) {
            $sent = (new WhatsAppCloud($entityId))->send($to164, $text);
            $status = $sent['ok'] ? 'accepted' : 'failed';
            $detail = $sent['ok'] ? 'Handed to WhatsApp. Delivery is not confirmed here.' : (string) $sent['error'];
            $url = null;
        } else {
            $status = 'prepared';
            $detail = 'WhatsApp opened with the message; it is sent only when the person presses send in WhatsApp.';
            $url = 'https://wa.me/'.ltrim($to164, '+').'?text='.rawurlencode($text);
        }
        $this->log($party, 'whatsapp', $to164, $from, $to, $filters, null, $status, $detail, $link['expires']);

        return ['status' => $status, 'url' => $url, 'detail' => $detail];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{status: string, detail: string}
     */
    public function email(Party $party, string $address, string $subject, string $message, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new BusinessRuleViolation('Not a valid e-mail address.');
        }
        $subject = mb_substr(trim($subject) ?: 'Account statement', 0, 190);
        $pdf = $this->pdf($party, $from, $to, $filters);
        try {
            Mail::to($address)->send(new PartyStatementMail($pdf, self::fileName($party, $to), $subject, trim($message)));
            $status = 'accepted';
            $detail = 'Handed to the mail server with the PDF attached. Delivery is not confirmed here.';
        } catch (Throwable $e) {
            $status = 'failed';
            $detail = 'Not sent: '.mb_substr($e->getMessage(), 0, 250);
        }
        $this->log($party, 'email', $address, $from, $to, $filters, $subject, $status, $detail);

        return ['status' => $status, 'detail' => $detail];
    }

    /** @param  array<string, mixed>  $filters */
    public function log(Party $party, string $channel, ?string $recipient, CarbonImmutable $from, CarbonImmutable $to, array $filters, ?string $subject,
        string $status, ?string $detail, ?CarbonImmutable $expires = null): StatementShare
    {
        $share = StatementShare::query()->create([
            'party_id' => $party->id, 'channel' => $channel, 'recipient' => $recipient, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
            'filters' => self::cleanFilters($filters), 'subject' => $subject, 'status' => $status, 'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null,
            'link_expires_at' => $expires, 'created_by' => $this->actor->accountId(), 'created_at' => now(),
        ]);
        $this->audit->record('party.statement_'.$channel, $party, null, null, ['status' => $status, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return $share;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    public static function cleanFilters(array $filters): array
    {
        $keep = ['type', 'product_id', 'vehicle', 'sauda_id', 'payment_status', 'view'];

        return array_map('strval', array_filter(array_intersect_key($filters, array_flip($keep)), fn ($v) => $v !== null && $v !== '' && $v !== 'all'));
    }
}
