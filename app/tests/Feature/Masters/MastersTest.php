<?php

declare(strict_types=1);

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Approvals\Actions\PartyBankAccountChange;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Auth\AuthContextBinder;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use App\Models\Masters\Vehicle;
use App\Models\Permission;
use App\Models\Role;
use App\Support\BlindIndex;
use App\Support\Eloquent\StaleModelException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('MST');
    $this->owner = Make::account($this->entity, 'OWNER');
    app(AuthContextBinder::class)->bind($this->owner);
});

function vendor(string $code = 'V1', ?string $gstin = '27AAPFU0939F1ZV'): Party
{
    return Party::query()->create([
        'code' => $code, 'name' => 'Sharma Traders', 'is_vendor' => true,
        'gst_registration_type' => $gstin ? 'regular' : 'unregistered', 'gstin' => $gstin,
    ]);
}

describe('parties', function () {
    it('encrypts GSTIN/PAN at rest, derives PAN and state, and indexes them for search', function () {
        $party = vendor(gstin: '27aapfu0939f1zv');
        $raw = DB::table('parties')->where('id', $party->id)->first();

        expect($party->gstin)->toBe('27AAPFU0939F1ZV')
            ->and($party->pan)->toBe('AAPFU0939F')
            ->and($party->state_code)->toBe('27')
            ->and($raw->gstin)->not->toContain('AAPFU0939F')
            ->and($raw->pan)->not->toContain('AAPFU0939F')
            ->and(Party::query()->where('gstin_index', BlindIndex::of('27AAPFU0939F1ZV', 'gstin'))->value('id'))->toBe($party->id);
    });

    it('keeps GSTIN unique per entity but allows it in another entity', function () {
        vendor('V1');
        expect(fn () => vendor('V2'))->toThrow(UniqueConstraintViolationException::class);

        $other = Make::entity('MS2');
        app(AuthContextBinder::class)->bind(Make::account($other, 'OWNER'));
        expect(vendor('V1')->entity_id)->toBe($other->id);
    });

    it('records masked identifiers in the audit log', function () {
        $party = vendor();
        $log = AuditLog::query()->where('action', 'party.created')->where('subject_id', $party->id)->sole();

        expect($log->new_values['gstin'])->toBe('***********F1ZV')
            ->and($log->new_values['pan'])->toBe('******939F')
            ->and(json_encode($log->new_values))->not->toContain('AAPFU0939F1ZV');
    });

    it('hides archived masters from normal queries', function () {
        $v = Vehicle::query()->create(['registration_no' => 'HR05AB1234', 'vehicle_type' => 'truck']);
        $v->forceFill(['archived_at' => now()])->save();

        expect(Vehicle::query()->count())->toBe(0)
            ->and(Vehicle::query()->withArchived()->count())->toBe(1);
    });

    it('rejects stale edits (optimistic locking)', function () {
        $a = Vehicle::query()->create(['registration_no' => 'HR05AB1234', 'vehicle_type' => 'truck']);
        $b = Vehicle::query()->findOrFail($a->id);
        $a->update(['vehicle_type' => 'tempo']);

        expect(fn () => $b->update(['vehicle_type' => 'tractor']))->toThrow(StaleModelException::class);
    });
});

describe('bank accounts (maker-checker)', function () {
    beforeEach(function () {
        $this->party = vendor();
        $this->exec = Make::account($this->entity, 'EXECUTIVE');
        $this->accountant = Make::account($this->entity, 'ACCOUNTANT');
        $this->approvals = app(ApprovalService::class);
    });

    function requestAdd(object $t, array $extra = []): ApprovalRequest
    {
        return $t->approvals->submit(PartyBankAccountChange::KEY, $extra + [
            'op' => 'add', 'party_id' => $t->party->id, 'account_holder' => 'Sharma Traders',
            'account_number' => '123456789012', 'ifsc' => 'sbin0001234', 'is_primary' => true,
        ], 'Add bank account for Sharma Traders', 'New vendor onboarding');
    }

    it('creates nothing until a different person with bank.view approves', function () {
        app(AuthContextBinder::class)->bind($this->exec);
        $request = requestAdd($this);

        expect(PartyBankAccount::query()->count())->toBe(0)
            // the account number is never stored in clear text in the request or the audit log
            ->and(json_encode($request->payload))->not->toContain('123456789012')
            ->and(AuditLog::query()->where('subject_id', $request->id)->get()->pluck('new_values')->toJson())->not->toContain('123456789012');

        // Owner2 without bank.view? Owners have bank.view; use an Executive as checker → refused.
        $exec2 = Make::account($this->entity, 'EXECUTIVE');
        expect($this->approvals->canDecide($exec2, $request))->toBeFalse();

        app(AuthContextBinder::class)->bind($this->owner);
        $done = $this->approvals->approve($request, $this->owner);

        $account = PartyBankAccount::query()->sole();
        $raw = DB::table('party_bank_accounts')->where('id', $account->id)->first();
        expect($done->status)->toBe('executed')
            ->and($account->account_number)->toBe('123456789012')
            ->and($account->ifsc)->toBe('SBIN0001234')
            ->and($account->maskedNumber())->toBe('XXXX9012')
            ->and($account->approval_request_id)->toBe($request->id)
            ->and($raw->account_number)->not->toContain('123456789012');
    });

    it('requires the maker permission and valid details', function () {
        $lab = Make::account($this->entity, 'LAB_TECHNICIAN');
        app(AuthContextBinder::class)->bind($lab);
        expect(fn () => requestAdd($this))->toThrow(ApprovalDenied::class);

        app(AuthContextBinder::class)->bind($this->exec);
        expect(fn () => requestAdd($this, ['ifsc' => 'BAD']))->toThrow(ValidationException::class)
            ->and(fn () => requestAdd($this, ['account_number' => '12ab']))->toThrow(ValidationException::class);
    });

    it('changes and deactivates only through approval, keeping one primary account', function () {
        app(AuthContextBinder::class)->bind($this->exec);
        $first = requestAdd($this);
        app(AuthContextBinder::class)->bind($this->owner);
        $this->approvals->approve($first, $this->owner);
        $acc1 = PartyBankAccount::query()->sole();

        app(AuthContextBinder::class)->bind($this->exec);
        $second = requestAdd($this, ['account_number' => '998877665544']);
        $deactivate = $this->approvals->submit(PartyBankAccountChange::KEY, [
            'op' => 'deactivate', 'party_id' => $this->party->id, 'bank_account_id' => $acc1->id,
        ], 'Deactivate old account');

        app(AuthContextBinder::class)->bind($this->owner);
        $this->approvals->approve($second, $this->owner);
        $this->approvals->approve($deactivate, $this->owner);

        $accounts = PartyBankAccount::query()->get()->keyBy('account_last4');
        expect($accounts['9012']->status)->toBe('inactive')
            ->and($accounts['9012']->is_primary)->toBeFalse()
            ->and($accounts['5544']->is_primary)->toBeTrue()
            ->and(PartyBankAccount::query()->count())->toBe(2); // nothing deleted
    });
});

it('grants a newly released permission to existing roles per template, and audits it', function () {
    // Simulate an installation that predates `sync.review`.
    Make::system(function () {
        DB::table('role_permissions')->where('permission_code', 'sync.review')->delete();
        Permission::query()->whereKey('sync.review')->delete();
    });
    $supervisor = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'SUPERVISOR')->sole());
    expect($supervisor->fresh()->permissionCodes())->not->toContain('sync.review');

    app(AccessCatalogSync::class)->sync();

    $gate = Make::system(fn () => Role::query()->where('entity_id', $this->entity->id)->where('code', 'GATE_OPERATOR')->sole());
    expect($supervisor->fresh()->permissionCodes())->toContain('sync.review')
        ->and($gate->fresh()->permissionCodes())->not->toContain('sync.review')
        ->and(Permission::query()->count())->toBe(count(PermissionCatalog::codes()))
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'role.permissions.granted')->where('meta', 'like', '%catalog_upgrade%')->count()))->toBeGreaterThan(0);
});
