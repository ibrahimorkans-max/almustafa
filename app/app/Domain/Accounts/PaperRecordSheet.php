<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Masters\Vehicle;
use App\Support\Export\XlsxWorkbook;
use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * The spreadsheet for old records, made easy to fill by hand: plain column names (in the order a register is read),
 * drop-down lists for the record type, who owes, how it was paid and the vehicle type, a "How to fill" sheet and
 * examples on their own sheet. Reads that Excel file back (or a CSV with the same or the older short column names).
 */
final class PaperRecordSheet
{
    public const SHEET = 'Old records';

    /** Column (as PaperRecords reads it) => the name written at the top of the sheet. */
    public const HEADERS = [
        'kind' => 'Record type *',
        'date' => 'Date * (DD/MM/YYYY)',
        'register_page' => 'Register & page',
        'paper_number' => 'Bill / slip no.',
        'party' => 'Party name',
        'party_phone' => 'Party phone',
        'party_cnic' => 'Party CNIC',
        'party_city' => 'Party city',
        'party_address' => 'Party address',
        'amount' => 'Amount (Rs)',
        'side' => 'Who owes (opening balance)',
        'account' => 'Account',
        'cash_bank' => 'Cash / bank account',
        'payment_mode' => 'Paid by',
        'reference' => 'Cheque / transaction no.',
        'cheque_date' => 'Cheque date',
        'party_bank' => "Party's bank",
        'handled_by' => 'Paid / received by (person)',
        'credit_account' => 'Account credited (journal)',
        'product' => 'Product',
        'godown' => 'Godown',
        'quantity_kg' => 'Weight (kg)',
        'rate_per_maund' => 'Rate per maund (Rs)',
        'vehicle' => 'Vehicle no.',
        'vehicle_type' => 'Vehicle type',
        'driver_name' => 'Driver name',
        'driver_phone' => 'Driver phone',
        'driver_cnic' => 'Driver CNIC',
        'vehicle_owner' => 'Vehicle owner',
        'from_place' => 'From (place)',
        'to_place' => 'To (place)',
        'freight' => 'Freight / kiraya (Rs)',
        'bags' => 'Bags',
        'bilty_no' => 'Bilty no.',
        'gross_kg' => 'Loaded weight (kg)',
        'tare_kg' => 'Empty weight (kg)',
        'description' => 'Details / note',
    ];

    /** Record type as written in the sheet => PaperRecords kind. */
    public const KINDS = [
        'Purchase' => 'purchase',
        'Sale' => 'sale',
        'Payment to party' => 'payment',
        'Money received' => 'receipt',
        'Expense' => 'expense',
        'Opening balance - party' => 'opening_party',
        'Opening balance - account' => 'opening_ledger',
        'Opening stock' => 'opening_stock',
        'Journal' => 'journal',
        'Weighbridge slip' => 'weighment',
        'Other entry' => 'note',
    ];

    public const SIDES = [
        'Party owes the mill (lena)' => 'receivable',
        'Mill owes the party (dena)' => 'payable',
        'Debit' => 'dr',
        'Credit' => 'cr',
    ];

    /** What to write in each column, and for which record types it is needed. */
    private const GUIDE = [
        'kind' => ['Choose from the list.', 'Every row'],
        'date' => ['The date written on the paper, e.g. 05/07/2025.', 'Every row'],
        'register_page' => ['Which register and page it is in, e.g. Roznamcha 2024, page 37.', 'Optional'],
        'paper_number' => ['The number printed or written on the bill, slip or receipt.', 'Optional'],
        'party' => ['The party\'s name (or code). A party not in the system is added.', 'Purchase, Sale, Payment, Money received, Opening balance - party'],
        'party_phone' => ['Mobile number, e.g. 0300-1234567. Filled in only where the party has none.', 'Optional'],
        'party_cnic' => ['13 digits, e.g. 36302-1234567-1.', 'Optional'],
        'party_city' => ['', 'Optional'],
        'party_address' => ['', 'Optional'],
        'amount' => ['Rupees, without commas or Rs.', 'Payment, Money received, Expense, Journal, Opening balances; Sale (or weight × rate)'],
        'side' => ['Choose from the list: for a party, who owed whom; for an account, Debit or Credit.', 'Opening balances'],
        'account' => ['The account name, e.g. Cash, Diesel, Rent.', 'Expense, Journal, Opening balance - account'],
        'cash_bank' => ['Cash, or the bank account it was paid from / into. Empty: Cash.', 'Payment, Money received, Expense'],
        'payment_mode' => ['Choose from the list. Empty: Cash.', 'Optional'],
        'reference' => ['The cheque number or the transaction ID.', 'When paid by cheque or bank transfer'],
        'cheque_date' => ['DD/MM/YYYY', 'Optional'],
        'party_bank' => ['e.g. HBL Lodhran.', 'Optional'],
        'handled_by' => ['Who paid or received it, e.g. Munshi Iqbal.', 'Optional'],
        'credit_account' => ['The account credited.', 'Journal'],
        'product' => ['The product name as in Masters → Products, e.g. Paddy 1121.', 'Opening stock; Purchase or Sale that moves stock'],
        'godown' => ['The godown name as in Masters → Godowns.', 'Opening stock; Purchase or Sale that moves stock'],
        'quantity_kg' => ['Kilograms (1 maund = 40 kg).', 'Opening stock; Purchase or Sale that moves stock'],
        'rate_per_maund' => ['Rupees per maund; with the weight, the amount is worked out.', 'Purchase, Sale (optional)'],
        'vehicle' => ['The number plate, e.g. MNA-21-1234. A vehicle not in the system is added.', 'Weighbridge slip; any record with a vehicle'],
        'vehicle_type' => ['Choose from the list.', 'Optional'],
        'driver_name' => ['', 'Optional'],
        'driver_phone' => ['Mobile number.', 'Optional'],
        'driver_cnic' => ['13 digits; only the last four are kept.', 'Optional'],
        'vehicle_owner' => ['', 'Optional'],
        'from_place' => ['Where the goods came from.', 'Optional'],
        'to_place' => ['Where the goods went.', 'Optional'],
        'freight' => ['The kiraya paid for the vehicle, in rupees.', 'Optional'],
        'bags' => ['Whole number.', 'Optional'],
        'bilty_no' => ['', 'Optional'],
        'gross_kg' => ['The loaded weight on the slip.', 'Weighbridge slip'],
        'tare_kg' => ['The empty weight on the slip.', 'Weighbridge slip'],
        'description' => ['Anything else written on the paper.', 'Journal, Other entry; optional for the rest'],
    ];

    /** The template: an empty sheet to fill (with drop-down lists), the guide, examples and the lists. */
    public static function template(): string
    {
        $columns = array_keys(self::HEADERS);
        $col = fn (string $key) => (int) array_search($key, $columns, true);
        $paidBy = array_values(PaperRecords::PAYMENT_MODES);
        $types = array_map(fn (string $t) => Vehicle::typeLabel($t), Vehicle::TYPES);
        $lists = [];
        foreach ([array_keys(self::KINDS), array_keys(self::SIDES), $paidBy, $types] as $c => $values) {
            foreach ($values as $r => $value) {
                $lists[$r][$c] = $value;
            }
        }
        $lists = array_map(fn (array $row) => array_replace(array_fill(0, 4, null), $row), $lists);
        $range = fn (string $letter, array $values) => "Lists!\${$letter}\$2:\${$letter}\$".(count($values) + 1);

        $examples = array_map(fn (array $e) => array_map(fn (string $c) => $e[$c] ?? null, $columns), self::examples());
        $guide = array_map(fn (string $c) => [self::HEADERS[$c], self::GUIDE[$c][0], self::GUIDE[$c][1]], $columns);

        return (new XlsxWorkbook)
            ->addSheet(self::SHEET, array_values(self::HEADERS), [], validations: [
                $col('kind') => $range('A', self::KINDS), $col('side') => $range('B', self::SIDES),
                $col('payment_mode') => $range('C', $paidBy), $col('vehicle_type') => $range('D', $types),
            ], minWidth: 14)
            ->addSheet('How to fill', ['Column', 'What to write', 'Needed for'], [
                ['One row for each entry on the paper. Fill the "'.self::SHEET.'" sheet, save, and import it. Columns with * are needed on every row.', '', ''],
                ...$guide,
            ])
            ->addSheet('Examples', array_values(self::HEADERS), $examples)
            ->addSheet('Lists', ['Record type', 'Who owes', 'Paid by', 'Vehicle type'], $lists)
            ->toString();
    }

    /**
     * The rows of an Excel file (the "Old records" sheet, else the first) or a CSV, first row = column names.
     *
     * @return list<list<string>>
     */
    public static function rows(string $bytes): array
    {
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return self::xlsxRows($bytes);
        }
        $lines = preg_split('/\r\n|\n|\r/', ltrim($bytes, "\xEF\xBB\xBF")) ?: [];
        $lines = array_values(array_filter($lines, fn (string $l) => trim($l, " \t,;") !== ''));
        if ($lines === []) {
            return [];
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

        return array_map(fn (string $line) => array_map(fn ($v) => trim((string) $v), str_getcsv($line, $delimiter)), $lines);
    }

    /** A column name as written (friendly or short) => the column PaperRecords reads; null when unknown. */
    public static function column(string $header): ?string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::HEADERS as $key => $name) {
                $map[self::key($name)] = $key;
                $map[self::key($key)] = $key;
            }
            $map += ['register' => 'register_page', 'bookref' => 'register_page', 'weight' => 'quantity_kg', 'kg' => 'quantity_kg',
                'gross' => 'gross_kg', 'tare' => 'tare_kg', 'kiraya' => 'freight', 'type' => 'kind', 'vehicleno' => 'vehicle'];
        }

        return $map[self::key($header)] ?? null;
    }

    /** A record type as written (from the list, the long name or the short code) => the kind. */
    public static function kind(string $value): string
    {
        foreach (self::KINDS as $label => $kind) {
            if (self::key($label) === self::key($value)) {
                return $kind;
            }
        }
        foreach (PaperRecords::KINDS as $kind => $label) {
            if (self::key($label) === self::key($value) || self::key($kind) === self::key($value)) {
                return $kind;
            }
        }

        return self::key($value);
    }

    public static function side(string $value): string
    {
        foreach (self::SIDES as $label => $side) {
            if (self::key($label) === self::key($value)) {
                return $side;
            }
        }

        return mb_strtolower(trim($value));
    }

    public static function paymentMode(string $value): string
    {
        foreach (PaperRecords::PAYMENT_MODES as $mode => $label) {
            if (self::key($label) === self::key($value)) {
                return $mode;
            }
        }

        return $value;
    }

    public static function vehicleType(string $value): string
    {
        foreach (Vehicle::TYPES as $type) {
            if (self::key(Vehicle::typeLabel($type)) === self::key($value)) {
                return $type;
            }
        }

        return $value;
    }

    /** Lower case letters and digits only, without "*" and anything in brackets: "Date * (DD/MM/YYYY)" → "date". */
    private static function key(string $text): string
    {
        $text = (string) preg_replace('/\([^)]*\)|\*/', '', mb_strtolower(trim($text)));

        return (string) preg_replace('/[^a-z0-9]/', '', $text);
    }

    /** @return list<list<string>> */
    private static function xlsxRows(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'old').'.xlsx';
        file_put_contents($path, $bytes);
        $reader = new Reader;
        try {
            $reader->open($path);
            $chosen = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($chosen === null || mb_strtolower($sheet->getName()) === mb_strtolower(self::SHEET)) {
                    $rows = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $cells = array_map(fn ($v) => match (true) {
                            $v instanceof DateTimeInterface => $v->format('d/m/Y'),
                            is_float($v) && floor($v) === $v && abs($v) < 1e15 => (string) (int) $v,
                            default => trim((string) $v),
                        }, $row->toArray());
                        if (implode('', $cells) !== '') {
                            $rows[] = $cells;
                        }
                    }
                    $chosen = $rows;
                    if (mb_strtolower($sheet->getName()) === mb_strtolower(self::SHEET)) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            throw new BusinessRuleViolation('The Excel file could not be read. Save it again as .xlsx (or CSV) and try again.');
        } finally {
            $reader->close();
            @unlink($path);
        }

        return $chosen ?? [];
    }

    /** @return list<array<string, string>> one example of each record type, as a person would write it */
    private static function examples(): array
    {
        return [
            ['kind' => 'Opening balance - party', 'date' => '30/06/2025', 'register_page' => 'Khata 2025, page 12', 'party' => 'Haji Aslam', 'party_phone' => '0300-1234567',
                'party_city' => 'Lodhran', 'amount' => '250000', 'side' => 'Party owes the mill (lena)', 'description' => 'Balance brought forward'],
            ['kind' => 'Opening balance - account', 'date' => '30/06/2025', 'register_page' => 'Cash book 2025, page 1', 'amount' => '85000', 'side' => 'Debit', 'account' => 'Cash'],
            ['kind' => 'Opening stock', 'date' => '30/06/2025', 'product' => 'Paddy 1121', 'godown' => 'Main godown', 'quantity_kg' => '20000', 'amount' => '1500000'],
            ['kind' => 'Purchase', 'date' => '05/07/2025', 'paper_number' => '112', 'party' => 'Rana Paddy Traders', 'product' => 'Paddy 1121', 'godown' => 'Main godown',
                'quantity_kg' => '12000', 'rate_per_maund' => '3200', 'vehicle' => 'MNA-21-1234', 'vehicle_type' => 'Truck', 'driver_name' => 'Muhammad Akram',
                'driver_phone' => '0302-1112233', 'from_place' => 'Kahror Pakka', 'freight' => '6000', 'bags' => '300', 'bilty_no' => 'B-778'],
            ['kind' => 'Sale', 'date' => '09/07/2025', 'paper_number' => '45', 'party' => 'Haji Aslam', 'amount' => '640000', 'vehicle' => 'LES-4455',
                'driver_name' => 'Ghulam Rasool', 'to_place' => 'Multan', 'bags' => '160', 'description' => 'Rice 1121 sella'],
            ['kind' => 'Payment to party', 'date' => '10/07/2025', 'party' => 'Rana Paddy Traders', 'amount' => '200000', 'cash_bank' => 'Cash', 'payment_mode' => 'Cash',
                'handled_by' => 'Munshi Iqbal'],
            ['kind' => 'Money received', 'date' => '12/07/2025', 'party' => 'Haji Aslam', 'amount' => '150000', 'cash_bank' => 'Meezan Bank — Current account',
                'payment_mode' => 'IBFT (online transfer)', 'reference' => 'TXN 88213'],
            ['kind' => 'Expense', 'date' => '12/07/2025', 'amount' => '3500', 'account' => 'Diesel', 'cash_bank' => 'Cash', 'vehicle' => 'MNA-21-1234',
                'description' => 'Diesel for the loader'],
            ['kind' => 'Weighbridge slip', 'date' => '05/07/2025', 'paper_number' => '3341', 'party' => 'Rana Paddy Traders', 'product' => 'Paddy 1121',
                'vehicle' => 'MNA-21-1234', 'driver_name' => 'Muhammad Akram', 'bags' => '300', 'gross_kg' => '32500', 'tare_kg' => '12500'],
            ['kind' => 'Other entry', 'date' => '20/07/2025', 'register_page' => 'Lab register, page 4', 'party' => 'Rana Paddy Traders', 'vehicle' => 'MNA-21-1234',
                'description' => 'Moisture 14%, broken 3%'],
        ];
    }
}
