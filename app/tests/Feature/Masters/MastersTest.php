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
use App\Support\Values\PakistanIds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('MST');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    app(AuthContextBinder::class)->bind($this->owner);
});

function vendor(string $code = 'V1', ?string $strn = '3277876543210'): Party
{
    return Party::query()->create([
        'code' => $code, 'name' => 'Lodhran Traders', 'is_vendor' => true,
        'strn' => $strn, 'ntn' => '1234567-8', 'cnic' => '36203-1234567-1',
    ]);
}

describe('parties', function () {
    it('encrypts NTN, CNIC and STRN at rest, marks the sales-tax registration, and indexes them for search', function () {
        $party = vendor(strn: '32-77-8765-432-10');
        $raw = DB::table('parties')->where('id', $party->id)->first();

        expect($party->strn)->toBe('3277876543210')
            ->and($party->ntn)->toBe('12345678')
            ->and($party->cnic)->toBe('3620312345671')
            ->and($party->tax_registration)->toBe('registered')
            ->and($party->isFiler())->toBeFalse()             // not checked on the ATL yet
            ->and($raw->strn)->not->toContain('3277876543210')
            ->and($raw->cnic)->not->toContain('3620312345671')
            ->and(Party::query()->where('strn_index', BlindIndex::of('3277876543210', 'strn'))->value('id'))->toBe($party->id)
            ->and(Party::query()->where('cnic_index', BlindIndex::of('3620312345671', 'cnic'))->value('id'))->toBe($party->id)
            ->and(vendor('V9', null)->tax_registration)->toBe('unregistered');
    });

    it('keeps the STRN unique per entity but allows it in another entity', function () {
        vendor('V1');
        expect(fn () => vendor('V2'))->toThrow(UniqueConstraintViolationException::class);

        $other = Make::entity('MS2');
        app(AuthContextBinder::class)->bind(Make::account($other, 'SUPER_ADMIN'));
        expect(vendor('V1')->entity_id)->toBe($other->id);
    });

    it('records masked identifiers in the audit log', function () {
        $party = vendor();
        $log = AuditLog::query()->where('action', 'party.created')->where('subject_id', $party->id)->sole();

        expect($log->new_values['strn'])->toBe('*********3210')
            ->and($log->new_values['cnic'])->toBe('*********5671')
            ->and(json_encode($log->new_values))->not->toContain('3277876543210')
            ->and(json_encode($log->new_values))->not->toContain('3620312345671');
    });

    it('hides archived masters from normal queries', function () {
        $v = Vehicle::query()->create(['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']);
        $v->forceFill(['archived_at' => now()])->save();

        expect(Vehicle::query()->count())->toBe(0)
            ->and(Vehicle::query()->withArchived()->count())->toBe(1);
    });

    it('rejects stale edits (optimistic locking)', function () {
        $a = Vehicle::query()->create(['registration_no' => 'MNA211234', 'vehicle_type' => 'truck']);
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
            'op' => 'add', 'party_id' => $t->party->id, 'account_holder' => 'Lodhran Traders',
            'account_number' => 'pk36 scbl 0000 0011 2345 6702', 'is_primary' => true,
        ], 'Add bank account for Lodhran Traders', 'New vendor onboarding');
    }

    it('creates nothing until a different person with bank.view approves', function () {
        app(AuthContextBinder::class)->bind($this->exec);
        $request = requestAdd($this);

        expect(PartyBankAccount::query()->count())->toBe(0)
            // the account number is never stored in clear text in the request or the audit log
            ->and(json_encode($request->payload))->not->toContain('PK36SCBL0000001123456702')
            ->and(AuditLog::query()->where('subject_id', $request->id)->get()->pluck('new_values')->toJson())->not->toContain('PK36SCBL0000001123456702');

        // Owner2 without bank.view? Owners have bank.view; use an Executive as checker → refused.
        $exec2 = Make::account($this->entity, 'EXECUTIVE');
        expect($this->approvals->canDecide($exec2, $request))->toBeFalse();

        app(AuthContextBinder::class)->bind($this->owner);
        $done = $this->approvals->approve($request, $this->owner);

        $account = PartyBankAccount::query()->sole();
        $raw = DB::table('party_bank_accounts')->where('id', $account->id)->first();
        expect($done->status)->toBe('executed')
            ->and($account->account_number)->toBe('PK36SCBL0000001123456702')
            ->and($account->bank_code)->toBe('SCBL')
            ->and($account->maskedNumber())->toBe('XXXX6702')
            ->and($account->approval_request_id)->toBe($request->id)
            ->and($raw->account_number)->not->toContain('PK36SCBL0000001123456702');
    });

    it('requires the maker permission and valid details', function () {
        $lab = Make::account($this->entity, 'LAB_TECHNICIAN');
        app(AuthContextBinder::class)->bind($lab);
        expect(fn () => requestAdd($this))->toThrow(ApprovalDenied::class);

        app(AuthContextBinder::class)->bind($this->exec);
        expect(fn () => requestAdd($this, ['account_number' => 'PK37SCBL0000001123456702']))->toThrow(ValidationException::class) // wrong check digits
            ->and(fn () => requestAdd($this, ['account_number' => '12ab']))->toThrow(ValidationException::class);
    });

    it('changes and deactivates only through approval, keeping one primary account', function () {
        app(AuthContextBinder::class)->bind($this->exec);
        $first = requestAdd($this);
        app(AuthContextBinder::class)->bind($this->owner);
        $this->approvals->approve($first, $this->owner);
        $acc1 = PartyBankAccount::query()->sole();

        app(AuthContextBinder::class)->bind($this->exec);
        $second = requestAdd($this, ['account_number' => PakistanIds::ibanFor('HABB', '0000000099887766')]);
        $deactivate = $this->approvals->submit(PartyBankAccountChange::KEY, [
            'op' => 'deactivate', 'party_id' => $this->party->id, 'bank_account_id' => $acc1->id,
        ], 'Deactivate old account');

        app(AuthContextBinder::class)->bind($this->owner);
        $this->approvals->approve($second, $this->owner);
        $this->approvals->approve($deactivate, $this->owner);

        $accounts = PartyBankAccount::query()->get()->keyBy('account_last4');
        expect($accounts['6702']->status)->toBe('inactive')
            ->and($accounts['6702']->is_primary)->toBeFalse()
            ->and($accounts['7766']->is_primary)->toBeTrue()
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
