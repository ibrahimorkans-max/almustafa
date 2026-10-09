<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Hr\EmployeeService;
use App\Models\Entity;
use App\Models\Hr\Department;
use App\Models\Hr\Employee;
use App\Models\LoginAccount;
use App\Support\Values\IndianIds;

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
        $this->owner = Make::account($entity, 'OWNER');
        $this->hr = Make::account($entity, 'HR_MANAGER');
        $this->accountant = Make::account($entity, 'ACCOUNTANT');
        $this->supervisor = Make::account($entity, 'SUPERVISOR');
        $this->gate = Make::account($entity, 'GATE_OPERATOR');
    }

    public function as(LoginAccount $account): void
    {
        app(AuthContextBinder::class)->bind($account);
    }

    /** A valid Aadhaar number (Verhoeff check digit) that differs per call. */
    public static function aadhaar(?int $n = null): string
    {
        $base = sprintf('5%010d', $n ?? ++self::$seq + random_int(1, 9_999_999));
        for ($d = 0; $d <= 9; $d++) {
            if (IndianIds::isValidAadhaar($base.$d)) {
                return $base.$d;
            }
        }
        throw new \LogicException('unreachable');
    }

    public static function pan(): string
    {
        return sprintf('ABCP%s%04dK', chr(65 + random_int(0, 25)), random_int(1000, 9999));
    }

    /** @param  array<string, mixed>  $overrides */
    public function employee(array $overrides = []): Employee
    {
        $this->as($this->hr);
        $dept = Department::query()->where('code', 'PROD')->value('id');

        return app(EmployeeService::class)->create($overrides + [
            'full_name' => 'Ramesh Kumar', 'phone' => '9812345678', 'department_id' => $dept,
            'employment_type' => 'permanent', 'joining_date' => '2026-04-01', 'date_of_birth' => '1990-05-17',
            'address' => 'Ward 4, Taraori, Karnal', 'aadhaar' => self::aadhaar(), 'pan' => self::pan(),
            'emergency_contact_name' => 'Sita Devi', 'emergency_contact_phone' => '9812300000',
        ]);
    }
}
