<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Files\Attachments;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\LoginAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccountsFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new AccountsFixtures(Make::entity('ATT'));
    $this->entry = $this->f->receiveLot();
    $this->owner = $this->f->owner;
    // A 1×1 PNG.
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
});

function attachSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($a, 'web')->withSession(Make::webSession($a));
    app(AuthContextBinder::class)->bind($a);
}

it('attaches a lab sheet to a vehicle and opens it, both in the audit log', function () {
    attachSignIn($this, $this->owner);
    $a = app(Attachments::class)->attach($this->entry, 'lab_result', $this->png, 'Moisture sheet');

    expect(app(Attachments::class)->of($this->entry)->pluck('id')->all())->toBe([$a->id])
        ->and($a->file->mime)->toBe('image/png');

    $this->get(route('filament.admin.attachment', ['id' => $a->id]))
        ->assertOk()->assertHeader('Content-Type', 'image/png');

    expect(AuditLog::query()->where('action', 'attachment.added')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'attachment.opened')->count())->toBe(1);
});

it('refuses the wrong kind of document, files that are not photos or PDFs, and people who may not see the record', function () {
    attachSignIn($this, $this->owner);
    $files = app(Attachments::class);

    expect(fn () => $files->attach($this->entry, 'payment_voucher', $this->png))->toThrow(BusinessRuleViolation::class, 'does not belong')
        ->and(fn () => $files->attach($this->entry, 'other', 'plain text, not a document'))->toThrow(BusinessRuleViolation::class, 'photo (JPEG, PNG) or a PDF');

    $a = $files->attach($this->entry, 'other', $this->png);
    $hr = Make::account($this->f->entity, 'HR_MANAGER');
    attachSignIn($this, $hr);
    expect(fn () => app(Attachments::class)->attach($this->entry, 'other', $this->png))->toThrow(BusinessRuleViolation::class, 'may not attach');
    $this->get(route('filament.admin.attachment', ['id' => $a->id]))->assertForbidden();
});

it('will not show a file that was altered after it was attached', function () {
    attachSignIn($this, $this->owner);
    $a = app(Attachments::class)->attach($this->entry, 'other', $this->png);
    Storage::disk('local')->put($a->file->path, 'tampered');

    $this->get(route('filament.admin.attachment', ['id' => $a->id]))->assertForbidden();
});

it('keeps attachments append-only', function () {
    attachSignIn($this, $this->owner);
    $a = app(Attachments::class)->attach($this->entry, 'other', $this->png);

    expect(fn () => $a->update(['note' => 'changed']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => Attachment::query()->whereKey($a->id)->delete())->toThrow(QueryException::class);
});
