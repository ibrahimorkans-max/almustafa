<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PurchaseBill;
use App\Models\ApprovalRequest;
use App\Models\Attachment;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Supporting documents on records: lab result sheets and weighbridge slips on a vehicle, supplier
 * invoices on a purchase bill, payment vouchers and bank proofs on a payment, papers on an approval.
 * Whoever may see the record may attach to it and open its files; files are photos (JPEG, PNG) or PDF,
 * at most 5 MB, stored by their SHA-256 (provably unchanged). Nothing attached is edited or removed;
 * every attachment and every opening is in the audit log.
 */
final class Attachments
{
    /** record type => [model class, kinds allowed] */
    public const TYPES = [
        'gate_entry' => [GateEntry::class, ['lab_result', 'weighbridge_slip', 'supplier_invoice', 'other']],
        'purchase_bill' => [PurchaseBill::class, ['supplier_invoice', 'weighbridge_slip', 'other']],
        'payment' => [Payment::class, ['payment_voucher', 'bank_proof', 'other']],
        'approval_request' => [ApprovalRequest::class, ['approval', 'other']],
    ];

    public const KINDS = [
        'lab_result' => 'Lab result sheet', 'weighbridge_slip' => 'Weighbridge slip', 'supplier_invoice' => 'Supplier invoice',
        'payment_voucher' => 'Payment voucher (signed)', 'bank_proof' => 'Bank transfer proof', 'approval' => 'Approval paper', 'other' => 'Other document',
    ];

    public function __construct(
        private readonly FileStore $files,
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
    ) {}

    public static function typeOf(Model $record): string
    {
        foreach (self::TYPES as $type => [$class]) {
            if ($record instanceof $class) {
                return $type;
            }
        }

        throw new BusinessRuleViolation('Files cannot be attached to this kind of record.');
    }

    /** @return array<string, string> the kinds of document this record takes, with their labels */
    public static function kindsFor(Model $record): array
    {
        return array_intersect_key(self::KINDS, array_flip(self::TYPES[self::typeOf($record)][1]));
    }

    public function attach(Model $record, string $kind, string $bytes, ?string $note = null): Attachment
    {
        $type = self::typeOf($record);
        $me = $this->actor->account();
        if (! $me instanceof LoginAccount || ! Gate::forUser($me)->allows('view', $record)) {
            throw new BusinessRuleViolation('You may not attach files to this record.');
        }
        if (! in_array($kind, self::TYPES[$type][1], true)) {
            throw new BusinessRuleViolation('This kind of document does not belong on this record.');
        }
        $file = $this->files->put($bytes);
        if (! in_array($file->mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new BusinessRuleViolation('Attach a photo (JPEG, PNG) or a PDF.');
        }
        $attachment = Attachment::query()->create([
            'attachable_type' => $type, 'attachable_id' => (string) $record->getKey(), 'stored_file_id' => $file->id,
            'kind' => $kind, 'note' => trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 300), 'uploaded_by' => $me->id,
        ]);
        $this->audit->record('attachment.added', $record, null, null, ['attachment' => $attachment->id, 'kind' => $kind, 'sha256' => $file->sha256]);

        return $attachment;
    }

    /** @return Collection<int, Attachment> */
    public function of(Model $record): Collection
    {
        return Attachment::query()->with(['file', 'uploader'])->where('attachable_type', self::typeOf($record))
            ->where('attachable_id', (string) $record->getKey())->orderBy('created_at')->get();
    }

    /** The record an attachment belongs to (null if gone or not visible in this mill). */
    public function recordOf(Attachment $attachment): ?Model
    {
        $class = self::TYPES[$attachment->attachable_type][0] ?? null;

        return $class === null ? null : $class::query()->find($attachment->attachable_id);
    }

    /** @return array{bytes: string, mime: string, name: string} the file, for someone who may see its record */
    public function open(Attachment $attachment): array
    {
        $record = $this->recordOf($attachment);
        $me = $this->actor->account();
        if ($record === null || ! $me instanceof LoginAccount || ! Gate::forUser($me)->allows('view', $record)) {
            throw new BusinessRuleViolation('You may not open this file.');
        }
        $file = $attachment->file;
        if (! $this->files->verify($file)) {
            throw new BusinessRuleViolation('This file does not match what was attached (it was altered); it is not shown.');
        }
        $this->audit->record('attachment.opened', $record, null, null, ['attachment' => $attachment->id]);
        $ext = match ($file->mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg',
        };

        return ['bytes' => (string) Storage::disk('local')->get($file->path), 'mime' => $file->mime,
            'name' => $attachment->kind.'-'.substr($attachment->id, 0, 8).'.'.$ext];
    }
}
