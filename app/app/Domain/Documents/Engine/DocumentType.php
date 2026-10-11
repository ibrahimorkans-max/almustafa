<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * One kind of document (payslip, advance voucher, payroll register …). It finds its source record,
 * says whether that record is final, and builds the language-neutral content from it:
 *
 *   number, date, period, title           what the header shows
 *   fields: [[label key, cell, sensitive?]] party / employee details (sensitive = cnic | iban: masked)
 *   tables: [{k, cols: [label keys], rows: [[cell …]], total?: [cell …], detail?: [column indexes]}]
 *   summary: [[label key, paise, strong?]]
 *   words: paise | null                    amount in words
 *   paragraphs: [[text key, vars]]         certificates, receipts
 *   extras: {employer|ytd|leave|loan|attendance: table, calendar: [[day, code] …]}  shown by toggle
 *   status: [[label key, cell]]            accounting link (voucher, paid / unpaid)
 *   signatures: [label key …]
 *
 * Cells are described in DocumentPresenter::cell().
 */
interface DocumentType
{
    public function key(): string;

    public function label(): string;

    /** @return class-string<Model> */
    public function sourceClass(): string;

    /** Final records are issued (numbered, stored, verifiable); others can only be previewed as DRAFT. */
    public function isFinal(Model $source): bool;

    /** The number the record already carries (payslip, advance, payment …), or null to take one from series(). */
    public function number(Model $source): ?string;

    public function series(): string;

    /** One document per source record (payslip), or a new one each time (certificate). */
    public function oncePerSource(): bool;

    public function employeeId(Model $source): ?string;

    public function defaultPaper(): string;

    /**
     * @param  array<string, mixed>  $options  generation options (e.g. a certificate's purpose)
     * @return array<string, mixed>
     */
    public function build(Model $source, array $options): array;
}
