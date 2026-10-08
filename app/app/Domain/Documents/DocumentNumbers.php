<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per entity, series and Indian financial year (April–March):
 *   KNL/P/2026-27/0001
 * The counter row is locked inside the caller's transaction, so a rolled-back document
 * does not consume a number.
 */
final class DocumentNumbers
{
    public const SAUDA_PURCHASE = 'P';

    public const SAUDA_SALE = 'S';

    public const REQUIREMENT = 'REQ';

    public const QUOTE = 'Q';

    public const GATE = 'G';

    public const REQUISITION = 'RQ';

    public const BATCH = 'B';

    public const PAYMENT_REQUEST = 'PMT';

    public const BANK_FILE = 'BF';

    public function __construct(private readonly TenantContext $tenant) {}

    public function next(string $series, ?CarbonInterface $date = null): string
    {
        $entityId = $this->tenant->requireEntityId();
        $fy = self::financialYear($date ?? CarbonImmutable::now());

        return DB::transaction(function () use ($entityId, $series, $fy): string {
            DB::table('document_sequences')->insertOrIgnore([
                'entity_id' => $entityId, 'series' => $series, 'financial_year' => $fy, 'last_number' => 0,
            ]);
            $query = fn () => DB::table('document_sequences')
                ->where('entity_id', $entityId)->where('series', $series)->where('financial_year', $fy);

            $number = (int) $query()->lockForUpdate()->value('last_number') + 1;
            $query()->update(['last_number' => $number]);

            $code = $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('code'));

            return sprintf('%s/%s/%s/%04d', $code, $series, $fy, $number);
        });
    }

    /** "2026-27" for any date from 1 Apr 2026 to 31 Mar 2027 (Indian time). */
    public static function financialYear(CarbonInterface $date): string
    {
        $local = CarbonImmutable::instance($date)->setTimezone('Asia/Kolkata');
        $start = $local->month >= 4 ? $local->year : $local->year - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }
}
