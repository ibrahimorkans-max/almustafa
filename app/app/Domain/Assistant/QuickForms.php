<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Models\Gate\LabParameter;
use App\Support\Values\UrduDigits;

/**
 * The assistant's quick buttons (New party, Vehicle arrived, Expense…): each writes a short message with blanks
 * into the chat box, the person fills the blanks and sends it, and the assistant shows the change for Confirm.
 * Before the message, what must exist first is shown (App\Domain\Assistant\Prerequisites).
 *
 *     New party
 *     Name: ____
 *     Acts as (customer / vendor / farmer / broker / transporter / contractor): ____
 *     Mobile (optional): ____
 *
 * It works without AI: the filled message is read here, line by line ("Label: value").
 */
final class QuickForms
{
    /** A blank still to fill. */
    public const BLANK = '____';

    /**
     * key => title (the message's first line), button label, icon, tool, record (Prerequisites), fields:
     * input name => [label, required].
     *
     * @var array<string, array{title: string, button: string, icon: string, tool: string, record: string, fields: array<string, array{0: string, 1: bool}>}>
     */
    public const FORMS = [
        'party' => ['title' => 'New party', 'button' => 'Add new party', 'icon' => 'heroicon-o-user-plus', 'tool' => 'create_party', 'record' => 'party', 'fields' => [
            'name' => ['Name', true],
            'acts_as' => ['Acts as (customer / vendor / farmer / broker / transporter / contractor)', true],
            'phone' => ['Mobile (optional)', false],
            'city' => ['City (optional)', false],
            'cnic' => ['CNIC (optional)', false],
        ]],
        'vehicle' => ['title' => 'Vehicle arrived', 'button' => 'Vehicle arrived', 'icon' => 'heroicon-o-truck', 'tool' => 'register_vehicle_arrival', 'record' => 'vehicle', 'fields' => [
            'registration_no' => ['Vehicle number', true],
            'party' => ['Party name', false],
            'type' => ['Type (with jack / without jack)', true],
            'item' => ['Item (paddy variety)', false],
            'sauda' => ['Sauda number (optional)', false],
            'bags' => ['Bags (optional)', false],
        ]],
        'lab' => ['title' => 'Lab test', 'button' => 'Lab test', 'icon' => 'heroicon-o-beaker', 'tool' => 'record_lab_test', 'record' => 'lab', 'fields' => [
            'vehicle' => ['Vehicle (number or token)', true],
            self::LAB_RESULTS => ['', false],
            'decision' => ['Decision (accept / reject / later)', false],
            'remarks' => ['Remarks (optional)', false],
        ]],
        'sauda' => ['title' => 'New purchase sauda', 'button' => 'New sauda', 'icon' => 'heroicon-o-document-plus', 'tool' => 'create_purchase_sauda', 'record' => 'sauda', 'fields' => [
            'seller' => ['Seller', true],
            'product' => ['Paddy', true],
            'maund' => ['Maund', true],
            'rate_per_maund' => ['Rate per maund (Rs)', true],
            'days' => ['Delivery days (optional, 30)', false],
        ]],
        'expense' => ['title' => 'New expense', 'button' => 'Add expense', 'icon' => 'heroicon-o-banknotes', 'tool' => 'record_expense', 'record' => 'expense', 'fields' => [
            'category' => ['Expense (e.g. diesel, bijli, tea)', true],
            'amount' => ['Amount (Rs)', true],
            'description' => ['For', true],
            'paid_from' => ['Paid from (cash / bank)', false],
        ]],
        'payment' => ['title' => 'New payment', 'button' => 'Pay a party', 'icon' => 'heroicon-o-credit-card', 'tool' => 'request_payment', 'record' => 'payment', 'fields' => [
            'party' => ['Pay to', true],
            'amount' => ['Amount (Rs)', true],
            'mode' => ['By (ibft / rtgs / raast / cheque / pay_order / cash)', false],
            'note' => ['Note (optional)', false],
        ]],
        'wages' => ['title' => 'Daily wages', 'button' => 'Daily wages', 'icon' => 'heroicon-o-user-group', 'tool' => 'record_daily_wages', 'record' => 'wages', 'fields' => [
            'work' => ['Work (e.g. loading, stitching)', true],
            'workers' => ['Workers', true],
            'group' => ['Labour group or leader (optional)', false],
            'quantity' => ['Days or bags (optional, 1)', false],
            'pay' => ['Pay (cash / bank / later)', false],
        ]],
    ];

    /** Where the lab form has one line per lab test of the mill (moisture, broken…). */
    public const LAB_RESULTS = '__lab_results__';

    /** Fields whose answer is one word from a list, kept in small letters. */
    private const CHOICES = ['paid_from', 'mode', 'pay', 'decision'];

    /** Fields that are numbers: Urdu digits and commas are tidied. */
    private const NUMBERS = ['maund', 'rate_per_maund', 'days', 'amount', 'workers', 'quantity', 'bags'];

    /** The message with blanks. */
    public static function template(string $key): string
    {
        $form = self::FORMS[$key];
        $lines = [$form['title']];
        foreach (self::fields($key) as [$label]) {
            $lines[] = $label.': '.self::BLANK;
        }

        return implode("\n", $lines);
    }

    /**
     * A filled quick message: the form's key, its tool, the values given and the required labels still blank.
     * Null when the message is not a quick form.
     *
     * @return array{key: string, tool: string, input: array<string, string>, blank: list<string>}|null
     */
    public static function parse(string $message): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $message) ?: []), fn (string $l) => $l !== ''));
        if (count($lines) < 2) {
            return null;
        }
        foreach (self::FORMS as $key => $form) {
            if (mb_strtolower(rtrim($lines[0], ':')) !== mb_strtolower($form['title'])) {
                continue;
            }
            $given = [];
            foreach (array_slice($lines, 1) as $line) {
                if (preg_match('/^(?<label>[^:]{1,120}):\s*(?<value>.*)$/u', $line, $m) === 1) {
                    $given[self::norm($m['label'])] = $m['value'];
                }
            }
            $input = [];
            $blank = [];
            foreach (self::fields($key) as $name => [$label, $required]) {
                $value = self::value($name, (string) ($given[self::norm($label)] ?? $given[self::norm(self::short($label))] ?? ''));
                if ($value === '') {
                    if ($required) {
                        $blank[] = self::short($label);
                    }
                } elseif (str_starts_with($name, 'r_')) {
                    $input['results'][substr($name, 2)] = $value;
                } else {
                    $input[$name] = $value;
                }
            }
            if ($key === 'lab' && empty($input['results'])) {
                $blank[] = 'at least one lab result';
            }

            return ['key' => $key, 'tool' => $form['tool'], 'input' => $input, 'blank' => $blank];
        }

        return null;
    }

    /**
     * A form's fields; the lab form gets one line per lab test of the mill (r_CODE), e.g. "Moisture / نمی (%)".
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function fields(string $key): array
    {
        $out = [];
        foreach (self::FORMS[$key]['fields'] as $name => $field) {
            if ($name !== self::LAB_RESULTS) {
                $out[$name] = $field;

                continue;
            }
            $params = LabParameter::query()->where('status', 'active')->orderBy('sort')->orderBy('code')->get()->unique('code');
            foreach ($params as $p) {
                $out['r_'.$p->code] = [$p->name_en.' / '.$p->name_ur.' ('.$p->unit.')', false];
            }
        }

        return $out;
    }

    /** "Acts as (customer / …)" → "Acts as". */
    public static function short(string $label): string
    {
        return trim((string) preg_replace('/\s*\(.*\)\s*$/u', '', $label));
    }

    private static function value(string $name, string $value): string
    {
        $value = trim((string) preg_replace('/_{2,}/u', '', $value));
        if ($value === '') {
            return '';
        }
        if (in_array($name, self::NUMBERS, true) || str_starts_with($name, 'r_')) {
            $value = str_replace([',', ' ', '/-'], '', UrduDigits::toLatin($value));
            $value = (string) preg_replace('/^rs\.?/i', '', $value);
        }

        return in_array($name, self::CHOICES, true) ? mb_strtolower($value) : $value;
    }

    private static function norm(string $label): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($label)));
    }
}
