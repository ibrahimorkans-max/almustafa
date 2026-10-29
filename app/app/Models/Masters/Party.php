<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Masters\MasterCodes;
use App\Models\LoginAccount;
use App\Support\BlindIndex;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Customer, vendor, broker / arhti, farmer or transporter (one party may be several).
 * NTN, CNIC and STRN are encrypted at rest with blind indexes for uniqueness and search.
 *
 * Tax status decides what the mill does on invoices and payments: a buyer without an STRN is
 * "unregistered" (further tax may apply), and a supplier who is not on FBR's Active Taxpayer List
 * ("non-filer") suffers the higher withholding rate.
 *
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property bool $is_customer
 * @property bool $wht_exempt
 * @property bool $is_vendor
 * @property bool $is_broker
 * @property bool $is_farmer
 * @property bool $is_transporter
 * @property bool $is_contractor a labour contractor (paid by the weight its vehicles unload, load or carry)
 * @property string|null $contractor_code CTR-ALI-0001
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string $tax_registration
 * @property string|null $strn
 * @property string|null $strn_index
 * @property string|null $ntn
 * @property string|null $ntn_index
 * @property string|null $cnic
 * @property string|null $cnic_index
 * @property string $atl_status
 * @property Carbon|null $atl_checked_on
 * @property int|null $credit_limit_paise
 * @property int|null $credit_days
 * @property string|null $login_account_id
 * @property bool $messages_on the party gets messages (login details, money entries, vehicles)
 * @property string $status
 * @property string|null $notes
 */
class Party extends MasterModel
{
    public const SYNC_TYPE = 'party';

    public const ROLES = ['is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter', 'is_contractor'];

    /** registered = has an STRN (sales tax); unregistered = everyone else. */
    public const REGISTRATION_TYPES = ['registered', 'unregistered'];

    /** FBR Active Taxpayer List: active = filer, inactive = non-filer, unknown = not checked yet (treated as non-filer). */
    public const ATL_STATUSES = ['active', 'inactive', 'unknown'];

    protected $fillable = [
        'code', 'name', 'legal_name', 'is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter', 'is_contractor',
        'contact_person', 'phone', 'email', 'address', 'city', 'province',
        'tax_registration', 'strn', 'ntn', 'cnic', 'atl_status', 'atl_checked_on',
        'credit_limit_paise', 'credit_days', 'status', 'notes', 'wht_exempt', 'messages_on',
    ];

    protected $hidden = ['strn_index', 'ntn_index', 'cnic_index'];

    /** @var list<string> */
    protected array $auditMasked = ['strn', 'ntn', 'cnic'];

    protected function casts(): array
    {
        return [
            'wht_exempt' => 'boolean', 'is_customer' => 'boolean', 'is_vendor' => 'boolean', 'is_broker' => 'boolean',
            'is_farmer' => 'boolean', 'is_transporter' => 'boolean', 'is_contractor' => 'boolean', 'messages_on' => 'boolean',
            'strn' => 'encrypted', 'ntn' => 'encrypted', 'cnic' => 'encrypted', 'atl_checked_on' => 'date',
            'credit_limit_paise' => 'integer', 'credit_days' => 'integer',
            'version' => 'integer', 'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Party $party): void {
            $party->strn = PakistanIds::digits($party->strn);
            $party->ntn = PakistanIds::digits($party->ntn);
            $party->cnic = PakistanIds::digits($party->cnic);
            // An STRN means the party is registered for sales tax.
            $party->tax_registration = $party->strn !== null ? 'registered' : 'unregistered';
            $party->atl_status ??= 'unknown';
            $party->phone = PakistanIds::normalizeMobile($party->phone) ?? $party->phone;
            $party->strn_index = BlindIndex::of($party->strn, 'strn');
            $party->ntn_index = BlindIndex::of($party->ntn, 'ntn');
            $party->cnic_index = BlindIndex::of($party->cnic, 'cnic');
            // A labour contractor is paid like a vendor, and gets its own permanent code (CTR-ALI-0001).
            if ($party->is_contractor) {
                $party->is_vendor = true;
                if (blank($party->contractor_code)) {
                    $party->contractor_code = app(MasterCodes::class)->contractorCode($party);
                }
            }
        });
    }

    /** On FBR's Active Taxpayer List (a "filer"). Unknown counts as non-filer. */
    public function isFiler(): bool
    {
        return $this->atl_status === 'active';
    }

    public function isSalesTaxRegistered(): bool
    {
        return $this->strn !== null;
    }

    /** @return HasMany<PartyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(PartyBankAccount::class);
    }

    /** @return list<string> */
    public function roleLabels(): array
    {
        return array_values(array_filter([
            $this->is_customer ? 'Customer' : null,
            $this->is_vendor ? 'Vendor' : null,
            $this->is_broker ? 'Broker / agent' : null,
            $this->is_farmer ? 'Farmer' : null,
            $this->is_transporter ? 'Transporter' : null,
            $this->is_contractor ? 'Labour contractor' : null,
        ]));
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'is_customer' => ['sometimes', 'boolean'],
            'is_vendor' => ['sometimes', 'boolean'],
            'is_broker' => ['sometimes', 'boolean'],
            'is_farmer' => ['sometimes', 'boolean'],
            'is_transporter' => ['sometimes', 'boolean'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:16'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', Rule::in(PakistanIds::PROVINCES)],
            'strn' => [
                'nullable',
                fn (string $attr, mixed $value, \Closure $fail) => PakistanIds::isValidStrn((string) PakistanIds::digits((string) $value))
                    ? null : $fail('The STRN must have 13 digits.'),
            ],
            'ntn' => [
                'nullable',
                fn (string $attr, mixed $value, \Closure $fail) => PakistanIds::isValidNtn((string) PakistanIds::digits((string) $value))
                    ? null : $fail('The NTN must be 7 digits and the check digit (1234567-8), or a 13-digit CNIC.'),
            ],
            'cnic' => [
                'nullable',
                fn (string $attr, mixed $value, \Closure $fail) => PakistanIds::isValidCnic((string) PakistanIds::digits((string) $value))
                    ? null : $fail('The CNIC must have 13 digits (35202-1234567-1).'),
            ],
            'atl_status' => ['sometimes', Rule::in(self::ATL_STATUSES)],
            'atl_checked_on' => ['nullable', 'date'],
            'credit_limit_paise' => ['nullable', 'integer', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', Rule::in(['active', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'messages_on' => ['sometimes', 'boolean'],
        ];
    }

    public static function writableFields(): array
    {
        return [
            'code', 'name', 'legal_name', 'is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter',
            'contact_person', 'phone', 'email', 'address', 'city', 'province',
            'strn', 'ntn', 'cnic', 'atl_status', 'atl_checked_on', 'credit_limit_paise', 'credit_days', 'status', 'notes',
        ];
    }

    /** NTN and STRN appear on invoices and are shown in full; the CNIC is masked unless the viewer may see bank details. */
    public function toSyncArray(LoginAccount $viewer): array
    {
        $data = $this->syncEnvelope();
        foreach (self::writableFields() as $field) {
            $data[$field] = $this->getAttribute($field);
        }
        if (! $viewer->hasPermission('bank.view')) {
            $data['cnic'] = self::masked($this->cnic);
        }

        return $data;
    }
}
