<?php

declare(strict_types=1);

namespace App\Domain\Hr\Payroll;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Alerts\AlertService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Files\FileStore;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Alert;
use App\Models\Hr\Employee;
use App\Models\Hr\PayrollBankFile;
use App\Models\Hr\PayrollItem;
use App\Models\Hr\PayrollPayment;
use App\Models\Hr\PayrollPaymentAck;
use App\Models\Hr\PayrollRun;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Paying a finalized payroll: one bank transfer file for everyone with an approved IBAN, cash
 * (only up to the s. 21(m) limit, with the employee's signature or thumb photo as acknowledgement)
 * or a cheque. Each payment is append-only and posts Dr Salaries payable / Cr bank-cash; nobody can
 * be paid more than their net pay.
 */
final class SalaryPaymentService
{
    /** format => [label, header] */
    public const FORMATS = [
        'generic' => ['Generic bulk transfer (IBAN, name, amount, reference)', ['Sr', 'Beneficiary IBAN', 'Beneficiary name', 'Amount', 'Reference', 'Debit IBAN']],
        'ibft' => ['IBFT bulk upload (with purpose code SALA)', ['Sr', 'Beneficiary Name', 'Beneficiary IBAN', 'Bank Code', 'Amount', 'Purpose Code', 'Payment Reference']],
    ];

    public function __construct(
        private readonly ActorContext $actor,
        private readonly AuditLogger $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly FileStore $files,
        private readonly PayrollSettings $settings,
        private readonly AlertService $alerts,
    ) {}

    public function due(PayrollItem $item): int
    {
        return max(0, $item->net_paise - $item->paidPaise());
    }

    /** paid | partial | unpaid | nothing (no net pay) */
    public function status(PayrollItem $item): string
    {
        $paid = $item->paidPaise();

        return match (true) {
            $item->net_paise <= 0 => 'nothing',
            $paid >= $item->net_paise => 'paid',
            $paid > 0 => 'partial',
            default => 'unpaid',
        };
    }

    /**
     * Everyone of the run still owed pay and with a valid IBAN goes into one file; the transfer is
     * posted as one voucher. Staff without an IBAN are listed back so they can be paid otherwise.
     *
     * @return array{file: PayrollBankFile|null, without_iban: list<string>}
     */
    public function bankFile(PayrollRun $run, LedgerAccount $bank, string $format, CarbonImmutable $date): array
    {
        $this->require('hr.payroll.approve');
        if ($bank->kind !== 'bank') {
            throw new BusinessRuleViolation('Choose the mill bank account the salaries are paid from.');
        }
        if (! array_key_exists($format, self::FORMATS)) {
            throw new BusinessRuleViolation('Unknown bank file format.');
        }

        return DB::transaction(function () use ($run, $bank, $format, $date): array {
            $run = $this->finalized($run);
            $rows = [];
            $without = [];
            foreach ($run->currentItems()->orderBy('employee->code')->get() as $item) {
                $due = $this->due($item);
                if ($due <= 0) {
                    continue;
                }
                $iban = Employee::query()->whereKey($item->employee_id)->first()?->bank_account_number;
                if ($iban === null || ! PakistanIds::isValidIban($iban)) {
                    $without[] = (string) $item->employee['code'];

                    continue;
                }
                $rows[] = [$item, $due, $iban];
            }
            if ($rows === []) {
                return ['file' => null, 'without_iban' => $without];
            }
            $total = array_sum(array_column($rows, 1));
            $file = PayrollBankFile::query()->create([
                'payroll_run_id' => $run->id, 'number' => $this->numbers->next(DocumentNumbers::BANK_FILE, $date), 'ledger_account_id' => $bank->id,
                'format' => $format, 'payment_count' => count($rows), 'total_paise' => $total, 'sha256' => str_repeat('0', 64), 'created_at' => now(),
            ]);
            $voucher = $this->books->post('payment', $date, [
                ['account' => $this->chart->system('salaries_payable'), 'dr' => $total, 'memo' => "Salaries {$run->month} · {$file->number}"],
                ['account' => $bank, 'cr' => $total, 'memo' => "Salaries {$run->month} · {$file->number}"],
            ], "Salary transfer {$run->month}: {$file->number} (".count($rows).' employees)', ['source_type' => 'payroll_bank_file', 'source_id' => $file->id]);
            foreach ($rows as [$item, $due, $iban]) {
                PayrollPayment::query()->create([
                    'payroll_run_id' => $run->id, 'payroll_item_id' => $item->id, 'employee_id' => $item->employee_id,
                    'number' => $this->numbers->next(DocumentNumbers::SALARY_PAYMENT, $date), 'amount_paise' => $due, 'mode' => 'bank',
                    'ledger_account_id' => $bank->id, 'bank_file_id' => $file->id, 'iban' => $iban, 'reference' => $file->number,
                    'paid_on' => $date->toDateString(), 'voucher_id' => $voucher->id, 'created_at' => now(),
                ]);
            }
            $file->forceFill(['voucher_id' => $voucher->id, 'sha256' => hash('sha256', $this->csv($file, audit: false))])->save();
            $this->audit->record('hr.payroll.bank_file_created', $file, null, null, ['payments' => count($rows), 'total_paise' => $total, 'without_iban' => $without]);

            return ['file' => $file, 'without_iban' => $without];
        });
    }

    /** The bank file with full IBANs: needs bank.view, is audited, and refuses a file changed since it was made. */
    public function csv(PayrollBankFile $file, bool $audit = true): string
    {
        if ($audit) {
            $this->require('bank.view');
        }
        $bank = LedgerAccount::query()->findOrFail($file->ledger_account_id);
        $out = fopen('php://temp', 'r+') ?: throw new BusinessRuleViolation('Cannot build the file.');
        fputcsv($out, self::FORMATS[$file->format][1], escape: '');
        $sr = 0;
        foreach (PayrollPayment::query()->where('bank_file_id', $file->id)->with('item')->orderBy('number')->get() as $p) {
            $name = self::safe((string) ($p->item->employee['name'] ?? ''));
            $amount = number_format($p->amount_paise / 100, 2, '.', '');
            $ref = self::safe(mb_substr("Salary {$p->item->employee['code']} ".substr((string) $file->number, -12), 0, 30));
            fputcsv($out, $file->format === 'ibft'
                ? [++$sr, $name, $p->iban, PakistanIds::bankCodeOfIban((string) $p->iban), $amount, 'SALA', $ref]
                : [++$sr, $p->iban, $name, $amount, $ref, $bank->bank_account_number], escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        if ($audit) {
            if (! hash_equals($file->sha256, hash('sha256', $csv))) {
                throw new BusinessRuleViolation("Salary file {$file->number} no longer matches what was created; do not upload it.");
            }
            $this->audit->record('hr.payroll.bank_file_downloaded', $file, meta: ['payments' => $file->payment_count, 'total_paise' => $file->total_paise]);
        }

        return $csv;
    }

    public function payCash(PayrollItem $item, LedgerAccount $cash, CarbonImmutable $date): PayrollPayment
    {
        if ($cash->kind !== 'cash') {
            throw new BusinessRuleViolation('Pay cash from a cash account.');
        }
        $limit = $this->settings->int('cash_salary_limit_paise', $date);
        if ($item->net_paise > $limit) {
            throw new BusinessRuleViolation('Salary above '.PayContext::money($limit).' a month is paid through the bank (Income Tax Ordinance s. 21(m)).');
        }

        return $this->pay($item, 'cash', $cash, $date, null);
    }

    public function payCheque(PayrollItem $item, LedgerAccount $bank, string $chequeNumber, CarbonImmutable $date): PayrollPayment
    {
        if ($bank->kind !== 'bank') {
            throw new BusinessRuleViolation('A cheque is drawn on a bank account.');
        }
        if (! preg_match('/^[0-9A-Za-z-]{3,20}$/', trim($chequeNumber))) {
            throw new BusinessRuleViolation('Enter the cheque number.');
        }

        return $this->pay($item, 'cheque', $bank, $date, trim($chequeNumber));
    }

    /** The employee's signature or thumb on the cash register, photographed. */
    public function acknowledge(PayrollPayment $payment, string $method, string $imageBytes): PayrollPaymentAck
    {
        $this->require('hr.payroll.run');
        if ($payment->mode !== 'cash') {
            throw new BusinessRuleViolation('Only cash payments are acknowledged this way.');
        }
        if (! in_array($method, ['signature', 'thumb'], true)) {
            throw new BusinessRuleViolation('Choose signature or thumb impression.');
        }
        if (PayrollPaymentAck::query()->where('payroll_payment_id', $payment->id)->exists()) {
            throw new BusinessRuleViolation('This payment is already acknowledged.');
        }
        $file = $this->files->put($imageBytes);
        if (! in_array($file->mime, ['image/jpeg', 'image/png'], true)) {
            throw new BusinessRuleViolation('Upload a photo (JPEG or PNG) of the signature or thumb impression.');
        }
        $ack = PayrollPaymentAck::query()->create(['payroll_payment_id' => $payment->id, 'method' => $method, 'file_id' => $file->id, 'created_at' => now()]);
        $this->audit->record('hr.payroll.cash_acknowledged', $payment, meta: ['method' => $method, 'file' => $file->sha256]);

        return $ack;
    }

    /** Daily: an alert for each cash payment older than the setting still without the employee's acknowledgement (once per payment). */
    public function remindUnacknowledged(): int
    {
        $days = $this->settings->int('ack_reminder_days', CarbonImmutable::now('Asia/Karachi'));
        $raised = 0;
        PayrollPayment::query()->where('mode', 'cash')->where('paid_on', '<=', CarbonImmutable::now('Asia/Karachi')->subDays($days)->toDateString())
            ->whereNotIn('id', PayrollPaymentAck::query()->select('payroll_payment_id'))->orderBy('paid_on')->get()
            ->each(function (PayrollPayment $p) use (&$raised): void {
                if (Alert::query()->where('type', 'hr_salary_ack_missing')->where('subject_id', $p->id)->exists()) {
                    return;
                }
                $who = $p->item->employee['name'].' ('.$p->item->employee['code'].')';
                $this->alerts->raise('hr_salary_ack_missing', 'warning', "Cash salary {$p->number} to {$who} is not acknowledged",
                    'Get the employee to sign or put a thumb impression on the cash register, photograph it and record it on the payroll.', $p,
                    ['employee_id' => $p->employee_id, 'paid_on' => $p->paid_on->toDateString(), 'amount_paise' => $p->amount_paise], 'hr.payroll.run');
                $raised++;
            });

        return $raised;
    }

    private function pay(PayrollItem $item, string $mode, LedgerAccount $from, CarbonImmutable $date, ?string $reference): PayrollPayment
    {
        $this->require('hr.payroll.approve');

        return DB::transaction(function () use ($item, $mode, $from, $date, $reference): PayrollPayment {
            /** @var PayrollItem $item */
            $item = PayrollItem::query()->lockForUpdate()->findOrFail($item->id);
            $run = $this->finalized(PayrollRun::query()->findOrFail($item->payroll_run_id));
            if ($item->calculation_no !== $run->calculation_no) {
                throw new BusinessRuleViolation('This is not the final calculation of the payroll.');
            }
            $due = $this->due($item);
            if ($due <= 0) {
                throw new BusinessRuleViolation("{$item->employee['code']} has already been paid in full.");
            }
            $number = $this->numbers->next(DocumentNumbers::SALARY_PAYMENT, $date);
            $voucher = $this->books->post('payment', $date, [
                ['account' => $this->chart->system('salaries_payable'), 'dr' => $due, 'memo' => "Salary {$run->month} {$item->employee['code']}"],
                ['account' => $from, 'cr' => $due, 'memo' => "Salary {$run->month} {$item->employee['code']}"],
            ], "Salary {$run->month} to {$item->employee['code']} {$item->employee['name']} ({$mode})", ['source_type' => 'payroll_payment', 'source_id' => $item->id]);
            $payment = PayrollPayment::query()->create([
                'payroll_run_id' => $run->id, 'payroll_item_id' => $item->id, 'employee_id' => $item->employee_id, 'number' => $number,
                'amount_paise' => $due, 'mode' => $mode, 'ledger_account_id' => $from->id, 'reference' => $reference,
                'paid_on' => $date->toDateString(), 'voucher_id' => $voucher->id, 'created_at' => now(),
            ]);
            $this->audit->record('hr.payroll.paid', $payment, null, null, ['mode' => $mode, 'amount_paise' => $due, 'voucher' => $voucher->number]);

            return $payment;
        });
    }

    private function finalized(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRun::FINALIZED) {
            throw new BusinessRuleViolation('Salaries are paid only from a finalized payroll.');
        }

        return $run;
    }

    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function require(string $permission): LoginAccount
    {
        $account = $this->actor->account();
        if (! ($account?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }

        return $account;
    }
}
