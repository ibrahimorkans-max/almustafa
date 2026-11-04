<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Resources\Masters\PartyResource;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Validator;

/** Adds a party (customer, vendor, farmer, broker, transporter, labour contractor) from a few words; its code is given automatically. */
final class CreatePartyTool extends Tool
{
    /** Words a person may use for each kind, in English or Roman Urdu. */
    private const KINDS = [
        'is_customer' => ['customer', 'buyer', 'kharidar', 'grahak'],
        'is_vendor' => ['vendor', 'supplier', 'seller', 'arhti'],
        'is_farmer' => ['farmer', 'grower', 'zamindar', 'kisan'],
        'is_broker' => ['broker', 'agent', 'dalal'],
        'is_transporter' => ['transporter', 'transport'],
        'is_contractor' => ['contractor', 'labour contractor', 'thekedar'],
    ];

    public function name(): string
    {
        return 'create_party';
    }

    public function description(): string
    {
        return 'Add a new party: name, what it acts as (customer, vendor/supplier, farmer/grower, broker, transporter, labour contractor; several separated by commas), '
            .'and optionally mobile phone, city, CNIC and NTN. The code is given automatically.';
    }

    public function properties(): array
    {
        return [
            'name' => ['type' => 'string'],
            'acts_as' => ['type' => 'string', 'description' => 'e.g. "farmer" or "customer, vendor".'],
            'phone' => ['type' => 'string', 'description' => 'Mobile, e.g. 0300-1234567.'],
            'city' => ['type' => 'string'],
            'cnic' => ['type' => 'string'],
            'ntn' => ['type' => 'string'],
        ];
    }

    public function required(): array
    {
        return ['name', 'acts_as'];
    }

    public function writes(): bool
    {
        return true;
    }

    public function availableTo(LoginAccount $me): bool
    {
        return PartyResource::canCreate();
    }

    public function patterns(): array
    {
        return ['/^(?:new|add|create)\s+(?<acts_as>customer|supplier|vendor|farmer|grower|broker|transporter|contractor)\s+(?<name>[\w .&\-]{2,80}?)(?:\s+(?<phone>(?:\+?92|0)3\d{2}[\s\-]?\d{7}))?(?:\s+(?:from|of|in)\s+(?<city>[\w ]{2,40}))?$/i'];
    }

    public function examples(): array
    {
        return ['new farmer Haji Aslam 03001234567 from Lodhran'];
    }

    /** @param  array<string, mixed>  $input */
    public function summary(array $input): string
    {
        $roles = self::roles((string) ($input['acts_as'] ?? ''));
        $same = trim((string) ($input['name'] ?? '')) === '' ? 0
            : Party::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $input['name']))])->count();

        return 'Add party "'.trim((string) ($input['name'] ?? '')).'" as '.($roles === [] ? '?' : implode(', ', array_map(fn ($r) => PartyResource::ROLE_OPTIONS[$r], $roles)))
            .(filled($input['phone'] ?? null) ? ', mobile '.$input['phone'] : '').(filled($input['city'] ?? null) ? ', '.$input['city'] : '')
            .(filled($input['cnic'] ?? null) ? ', CNIC given' : '').(filled($input['ntn'] ?? null) ? ', NTN given' : '').'.'
            .($same > 0 ? ' Note: a party with this name already exists.' : '');
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        if (! PartyResource::canCreate()) {
            return ToolResult::fail('You may not add parties.');
        }
        $roles = self::roles((string) $input['acts_as']);
        if ($roles === []) {
            return ToolResult::fail('Say what the party acts as: customer, vendor, farmer, broker, transporter or labour contractor.');
        }
        $data = array_map(fn ($v) => is_string($v) ? trim($v) : $v, [
            'name' => $input['name'], 'phone' => $input['phone'] ?? null, 'city' => $input['city'] ?? null,
            'cnic' => $input['cnic'] ?? null, 'ntn' => $input['ntn'] ?? null,
        ]);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        $v = Validator::make($data, [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['nullable', 'string', 'max:16', fn ($a, $value, $fail) => PakistanIds::normalizeMobile((string) $value) === null ? $fail('The mobile number is not a Pakistani mobile (e.g. 0300-1234567).') : null],
            'city' => ['nullable', 'string', 'max:80'],
            'cnic' => ['nullable', 'string', fn ($a, $value, $fail) => strlen(PakistanIds::digits((string) $value) ?? '') !== 13 ? $fail('The CNIC has 13 digits.') : null],
            'ntn' => ['nullable', 'string', 'max:20'],
        ]);
        if ($v->fails()) {
            return ToolResult::fail(implode(' ', $v->errors()->all()));
        }
        $party = new Party($data + ['status' => 'active', 'messages_on' => true, 'atl_status' => 'unknown', 'province' => 'Punjab']);
        foreach (Party::ROLES as $role) {
            $party->{$role} = in_array($role, $roles, true);
        }
        $party->save();

        return new ToolResult("Party added: {$party->code} — {$party->name}.", [
            ['label' => 'Open the party', 'url' => PartyResource::getUrl('edit', ['record' => $party])],
        ]);
    }

    /** @return list<string> the role columns named in the words */
    public static function roles(string $words): array
    {
        $words = mb_strtolower($words);
        $out = [];
        foreach (self::KINDS as $role => $names) {
            foreach ($names as $name) {
                if (preg_match('/\b'.preg_quote($name, '/').'s?\b/u', $words) === 1) {
                    $out[] = $role;
                    break;
                }
            }
        }

        return $out;
    }
}
