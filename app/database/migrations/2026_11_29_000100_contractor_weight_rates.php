<?php

declare(strict_types=1);

use App\Support\Database\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Labour contractors paid by weight, per vehicle:
 *  - parties: a contractor flag and its permanent code (CTR-ALI-0001);
 *  - vehicles: the contractor a vehicle works for (one contractor per vehicle);
 *  - contractor_rates: a code (CRL-000001), the contractor's vehicle the rate is for, a custom rate weight
 *    (e.g. Rs 50 per 100 kg), and an end date, so versions of a rate never overlap;
 *  - contractor_bills: deductions and what has been paid (partial payments);
 *  - contractor_bill_payments: each payment against a bill, with its voucher.
 * Rates per vehicle, per bag and per ton already saved stay readable and billable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('is_contractor')->default(false);
            $table->string('contractor_code', 30)->nullable();
            $table->unique(['entity_id', 'contractor_code']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignUuid('contractor_party_id')->nullable()->constrained('parties');
            $table->index(['entity_id', 'contractor_party_id']);
        });

        Schema::table('contractor_rates', function (Blueprint $table) {
            $table->string('code', 20)->nullable();
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles');
            $table->decimal('basis_qty', 12, 3)->nullable();   // custom: the weight the rate is for (100 …)
            $table->string('basis_unit', 10)->nullable();      // custom: kg | maund | ton
            $table->date('effective_to')->nullable();
            $table->unique(['entity_id', 'code']);
        });

        Schema::table('contractor_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('deduction_paise')->default(0);
            $table->string('deduction_note', 300)->nullable();
            $table->unsignedBigInteger('paid_paise')->default(0);
        });

        Schema::create('contractor_bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('entity_id')->constrained('entities');
            $table->foreignUuid('contractor_bill_id')->constrained('contractor_bills');
            $table->date('paid_on');
            $table->unsignedBigInteger('amount_paise');
            $table->string('mode', 10);                        // cash | bank
            $table->foreignUuid('account_id')->constrained('ledger_accounts');
            $table->string('reference', 120)->nullable();
            $table->foreignUuid('voucher_id')->nullable()->constrained('vouchers');
            $table->uuid('created_by')->nullable();
            $table->dateTime('created_at', 3);
            $table->index(['entity_id', 'contractor_bill_id']);
        });

        TenantTables::secure(['contractor_bill_payments'], ['contractor_bill_payments']);

        // Parties already working as labour contractors are marked so, and existing rates get their code.
        $contractors = DB::table('parties')->where(fn ($q) => $q
            ->whereIn('id', DB::table('contractor_rates')->select('party_id'))
            ->orWhereIn('id', DB::table('contractor_bills')->select('party_id'))
            ->orWhereIn('id', DB::table('employees')->whereNotNull('contractor_party_id')->select('contractor_party_id'))
            ->orWhereIn('id', DB::table('gate_entries')->whereNotNull('contractor_party_id')->select('contractor_party_id')))
            ->orderBy('created_at')->get(['id', 'entity_id', 'name']);
        foreach ($contractors as $p) {
            $n = $this->next($p->entity_id, 'CODE-CTR');
            $first = strtok(trim((string) $p->name), " \t-_/&.,") ?: '';
            $abbr = substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper($first)), 0, 6) ?: 'X';
            DB::table('parties')->where('id', $p->id)->update(['is_contractor' => true, 'contractor_code' => sprintf('CTR-%s-%04d', $abbr, $n)]);
        }
        foreach (DB::table('contractor_rates')->orderBy('created_at')->get(['id', 'entity_id']) as $r) {
            DB::table('contractor_rates')->where('id', $r->id)->update(['code' => sprintf('CRL-%06d', $this->next($r->entity_id, 'CODE-CRL'))]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_bill_payments');
        Schema::table('contractor_bills', fn (Blueprint $table) => $table->dropColumn(['deduction_paise', 'deduction_note', 'paid_paise']));
        Schema::table('contractor_rates', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'code']);
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn(['code', 'basis_qty', 'basis_unit', 'effective_to']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['entity_id', 'contractor_party_id']);
            $table->dropConstrainedForeignId('contractor_party_id');
        });
        Schema::table('parties', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'contractor_code']);
            $table->dropColumn(['is_contractor', 'contractor_code']);
        });
    }

    private function next(string $entityId, string $series): int
    {
        DB::table('document_sequences')->insertOrIgnore(['entity_id' => $entityId, 'series' => $series, 'financial_year' => 'ALL', 'last_number' => 0]);
        $q = fn () => DB::table('document_sequences')->where('entity_id', $entityId)->where('series', $series)->where('financial_year', 'ALL');
        $n = (int) $q()->value('last_number') + 1;
        $q()->update(['last_number' => $n]);

        return $n;
    }
};
