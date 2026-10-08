<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\LoginAccount;
use App\Support\BlindIndex;
use App\Support\Values\IndianIds;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * Customer, vendor, broker/agent, farmer or transporter (one party may be several).
 * GSTIN and PAN are encrypted at rest with blind indexes for uniqueness and search.
 *
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property bool $is_customer
 * @property bool $tds_194q_exempt
 * @property bool $is_vendor
 * @property bool $is_broker
 * @property bool $is_farmer
 * @property bool $is_transporter
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state_code
 * @property string|null $pincode
 * @property string $gst_registration_type
 * @property string|null $gstin
 * @property string|null $gstin_index
 * @property string|null $pan
 * @property string|null $pan_index
 * @property int|null $credit_limit_paise
 * @property int|null $credit_days
 * @property string|null $login_account_id
 * @property string $status
 * @property string|null $notes
 */
class Party extends MasterModel
{
    public const SYNC_TYPE = 'party';

    public const ROLES = ['is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter'];

    public const REGISTRATION_TYPES = ['regular', 'composition', 'unregistered', 'consumer'];

    protected $fillable = [
        'code', 'name', 'legal_name', 'is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter',
        'contact_person', 'phone', 'email', 'address', 'city', 'state_code', 'pincode',
        'gst_registration_type', 'gstin', 'pan', 'credit_limit_paise', 'credit_days', 'status', 'notes', 'tds_194q_exempt',
    ];

    protected $hidden = ['gstin_index', 'pan_index'];

    /** @var list<string> */
    protected array $auditMasked = ['gstin', 'pan'];

    protected function casts(): array
    {
        return [
            'tds_194q_exempt' => 'boolean', 'is_customer' => 'boolean', 'is_vendor' => 'boolean', 'is_broker' => 'boolean',
            'is_farmer' => 'boolean', 'is_transporter' => 'boolean',
            'gstin' => 'encrypted', 'pan' => 'encrypted',
            'credit_limit_paise' => 'integer', 'credit_days' => 'integer',
            'version' => 'integer', 'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Party $party): void {
            $party->gstin = IndianIds::normalizeGstin($party->gstin);
            $party->pan = $party->pan !== null ? (strtoupper(trim($party->pan)) ?: null) : null;
            // A registered party's PAN is part of its GSTIN.
            if ($party->gstin !== null && $party->pan === null) {
                $party->pan = IndianIds::panFromGstin($party->gstin);
            }
            if ($party->gstin !== null && $party->state_code === null) {
                $party->state_code = substr($party->gstin, 0, 2);
            }
            $party->phone = IndianIds::normalizeMobile($party->phone) ?? $party->phone;
            $party->gstin_index = BlindIndex::of($party->gstin, 'gstin');
            $party->pan_index = BlindIndex::of($party->pan, 'pan');
        });
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
        ]));
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
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
            'state_code' => ['nullable', 'regex:/^\d{2}$/'],
            'pincode' => ['nullable', 'regex:/^[1-9]\d{5}$/'],
            'gst_registration_type' => ['required', Rule::in(self::REGISTRATION_TYPES)],
            'gstin' => [
                'nullable', 'required_if:gst_registration_type,regular,composition',
                fn (string $attr, mixed $value, \Closure $fail) => IndianIds::isValidGstin((string) IndianIds::normalizeGstin((string) $value))
                    ? null : $fail('The GSTIN is not valid (format or check digit).'),
            ],
            'pan' => [
                'nullable',
                fn (string $attr, mixed $value, \Closure $fail) => IndianIds::isValidPan(strtoupper(trim((string) $value)))
                    ? null : $fail('The PAN is not valid.'),
            ],
            'credit_limit_paise' => ['nullable', 'integer', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', Rule::in(['active', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public static function writableFields(): array
    {
        return [
            'code', 'name', 'legal_name', 'is_customer', 'is_vendor', 'is_broker', 'is_farmer', 'is_transporter',
            'contact_person', 'phone', 'email', 'address', 'city', 'state_code', 'pincode',
            'gst_registration_type', 'gstin', 'pan', 'credit_limit_paise', 'credit_days', 'status', 'notes',
        ];
    }

    /** GSTIN appears on invoices and is shown in full; PAN is masked unless the viewer may see bank details. */
    public function toSyncArray(LoginAccount $viewer): array
    {
        $data = $this->syncEnvelope();
        foreach (self::writableFields() as $field) {
            $data[$field] = $this->getAttribute($field);
        }
        if (! $viewer->hasPermission('bank.view')) {
            $data['pan'] = self::masked($this->pan);
        }

        return $data;
    }
}
