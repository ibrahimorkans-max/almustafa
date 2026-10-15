<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Hr\EmployeeService;
use App\Models\Entity;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;

/** An entity with the people HR work involves, and realistic employee data. */
final class HrFixtures
{
    public LoginAccount $owner;

    public LoginAccount $hr;

    public LoginAccount $accountant;

    public LoginAccount $supervisor;

    public LoginAccount $gate;

    private static int $seq = 0;

    public function __construct(public Entity $entity)
    {
        $this->owner = Make::account($entity, 'SUPER_ADMIN');
        $this->hr = Make::account($entity, 'HR_MANAGER');
        $this->accountant = Make::account($entity, 'ACCOUNTANT');
        $this->supervisor = Make::account($entity, 'SUPERVISOR');
        $this->gate = Make::account($entity, 'GATE_OPERATOR');
    }

    public function as(LoginAccount $account): void
    {
        app(AuthContextBinder::class)->bind($account);
    }

    /** A valid 13-digit CNIC (Punjab region) that differs per call. */
    public static function cnic(?int $n = null): string
    {
        return sprintf('36%011d', $n ?? ++self::$seq * 7919 + random_int(1, 9_999_999));
    }

    /** A valid NTN (7 digits and the check digit). */
    public static function ntn(): string
    {
        return sprintf('%08d', random_int(1_000_000, 99_999_999));
    }

    /** @param  array<string, mixed>  $overrides */
    public function employee(array $overrides = []): Employee
    {
        $this->as($this->hr);
        $dept = Department::query()->where('code', 'PROD')->value('id');

        return app(EmployeeService::class)->create($overrides + [
            'full_name' => 'Muhammad Iqbal', 'phone' => '03001234567', 'department_id' => $dept,
            'employment_type' => 'permanent', 'joining_date' => '2026-04-01', 'date_of_birth' => '1990-05-17',
            'address' => 'Mohalla Islampura, Lodhran', 'cnic' => self::cnic(), 'ntn' => self::ntn(),
            'emergency_contact_name' => 'Rashida Bibi', 'emergency_contact_phone' => '03001230000',
        ]);
    }
}
