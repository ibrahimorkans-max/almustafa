<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\EntityProvisioner;
use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens a new customer (SaaS) in one transaction: the company, its plan and trial, its first mill (with
 * the mill's roles, chart of accounts, HR and tax defaults) and its first Owner login. Only the platform
 * admin opens customers (Platform → Customers); there is no public sign-up.
 */
final class CustomerOnboarding
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EntityProvisioner $provisioner,
        private readonly AccessCatalogSync $catalog,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{company: string, contact_name?: string|null, phone?: string|null, email?: string|null,
     *               address?: string|null, notes?: string|null, plan_id?: string|null, status?: string,
     *               trial_days?: int|null, mill_name?: string|null, mill_code?: string|null, city?: string|null,
     *               province?: string|null, owner_name: string, username: string, password: string,
     *               must_change_password?: bool}  $data
     * @return array{customer: Customer, mill: Entity, owner: LoginAccount}
     */
    public function open(array $data, string $source): array
    {
        $username = mb_strtolower(trim($data['username']));
        $status = $data['status'] ?? Customer::TRIAL;
        $trialDays = (int) ($data['trial_days'] ?? config('ricemill.saas.trial_days'));

        $result = $this->tenant->runAsSystem(fn () => DB::transaction(function () use ($data, $username, $status, $trialDays, $source): array {
            if (LoginAccount::query()->where('username', $username)->exists()) {
                throw new BusinessRuleViolation('This username is taken. Choose another.');
            }
            $customer = Customer::query()->create([
                'code' => EntityProvisioner::nextCustomerCode(),
                'name' => trim($data['company']),
                'status' => $status,
                'plan_id' => $data['plan_id'] ?? $this->trialPlanId(),
                'trial_ends_at' => $status === Customer::TRIAL ? now('Asia/Karachi')->addDays(max(1, $trialDays))->endOfDay()->utc() : null,
                'source' => $source,
                'contact_name' => $data['contact_name'] ?? $data['owner_name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $millName = trim((string) ($data['mill_name'] ?? '')) ?: $customer->name;
            $mill = $this->provisioner->create([
                'customer_id' => $customer->id,
                'code' => $this->millCode($data['mill_code'] ?? null, $millName),
                'name' => $millName,
                'legal_name' => $millName,
                'city' => $data['city'] ?? null,
                'province' => $data['province'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => Entity::STATUS_ACTIVE,
            ]);
            $person = new Person(['full_name' => trim($data['owner_name']), 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null]);
            $person->created_in_entity_id = $mill->id;
            $person->save();
            $owner = LoginAccount::query()->create([
                'entity_id' => $mill->id,
                'person_id' => $person->id,
                'role_id' => $this->catalog->ownerRole()->id,
                'username' => $username,
                'password' => $data['password'],
                'status' => LoginAccount::STATUS_ACTIVE,
                'must_change_password' => (bool) ($data['must_change_password'] ?? false),
            ]);

            return ['customer' => $customer, 'mill' => $mill, 'owner' => $owner];
        }));

        $this->audit->record('saas.customer.opened', $result['customer'], null, [
            'code' => $result['customer']->code, 'status' => $status, 'mill' => $result['mill']->code, 'owner' => $username,
        ], ['source' => $source]);
        $this->tellPlatform($result['customer'], $source);

        return $result;
    }

    /** The short mill code: the one given, else the name's initials (AMRM), made unique with a number. */
    public function millCode(?string $given, string $name): string
    {
        $given = strtoupper(trim((string) $given));
        if ($given !== '') {
            if ($this->tenant->runAsSystem(fn () => Entity::query()->where('code', $given)->exists())) {
                throw new BusinessRuleViolation("The mill code {$given} is taken. Choose another.");
            }

            return $given;
        }
        $words = preg_split('/[^A-Za-z0-9]+/', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['MILL'];
        $base = strtoupper(substr(implode('', array_map(fn (string $w) => $w[0], $words)), 0, 6));
        $base = strlen($base) < 2 ? strtoupper(substr(implode('', $words).'MILL', 0, 4)) : $base;
        $code = $base;
        for ($i = 2; $this->tenant->runAsSystem(fn () => Entity::query()->where('code', $code)->exists()); $i++) {
            $code = $base.$i;
        }

        return $code;
    }

    private function trialPlanId(): ?string
    {
        return Plan::query()->where('code', config('ricemill.saas.trial_plan'))->where('active', true)->value('id');
    }

    /** A bell notice to the platform's own accounts. */
    private function tellPlatform(Customer $customer, string $source): void
    {
        $admins = $this->tenant->runAsSystem(fn () => LoginAccount::query()->where('is_platform_admin', true)
            ->where('status', LoginAccount::STATUS_ACTIVE)->get());
        $how = $source === 'signup' ? 'signed up' : 'was added';
        $trial = $customer->trial_ends_at ? ', free trial until '.$customer->trial_ends_at->format('d M Y') : '';
        Billing::notify($admins, "New customer: {$customer->name}", "{$customer->code} {$how}{$trial}.");
    }
}
