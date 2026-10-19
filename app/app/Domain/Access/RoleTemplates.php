<?php

declare(strict_types=1);

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * Default roles copied into every new entity. OWNER is global: the top account over every mill, with
 * every right. SUPER_ADMIN is each mill's administrator: everything inside that mill only.
 * After provisioning, grants change only through maker-checker approval.
 */
final class RoleTemplates
{
    public const OWNER = 'OWNER';

    public const SUPER_ADMIN = 'SUPER_ADMIN';

    public const EXECUTIVE = 'EXECUTIVE';

    public const ACCOUNTANT = 'ACCOUNTANT';

    public const SUPERVISOR = 'SUPERVISOR';

    public const INVENTORY_MANAGER = 'INVENTORY_MANAGER';

    public const LAB_TECHNICIAN = 'LAB_TECHNICIAN';

    public const GATE_OPERATOR = 'GATE_OPERATOR';

    public const BROKER = 'BROKER';

    public const HR_MANAGER = 'HR_MANAGER';

    public const EMPLOYEE = 'EMPLOYEE';

    /** Only the Archive screen: view archived records by period and restore them. */
    public const OFFICE_COORDINATOR = 'OFFICE_COORDINATOR';

    /**
     * @return array<string, array{name: string, global: bool, mfa_required: bool, session_max_minutes: int, permissions: list<string>}>
     */
    public static function all(): array
    {
        $all = PermissionCatalog::codes();
        $except = static fn (string ...$excluded): array => array_values(array_diff($all, $excluded));

        $templates = [
            self::OWNER => [
                'name' => 'Owner',
                'global' => true,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => $except('deal.view_own', 'commission.view_own'),
            ],
            self::SUPER_ADMIN => [
                'name' => 'Owner',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => $except('entity.manage', 'deal.view_own', 'commission.view_own'),
            ],
            self::EXECUTIVE => [
                'name' => 'Executive',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'user.view', 'role.view', 'approval.view',
                    'master.view', 'master.manage',
                    'requirement.broadcast', 'quote.view', 'quote.manage', 'deal.view', 'deal.create',
                    'production.view', 'stock.view', 'requisition.create',
                    'sales.view', 'sales.order', 'dispatch.manage',
                    'claim.view', 'claim.manage',
                    'pricing.view', 'dashboard.view', 'report.view',
                ],
            ],
            self::ACCOUNTANT => [
                'name' => 'Accountant',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'approval.view',
                    'master.view', 'master.manage',
                    'deal.view', 'quote.view', 'stock.view', 'sales.view', 'claim.view',
                    'ledger.view', 'ledger.post', 'voucher.reverse', 'payment.create', 'bank.view',
                    'pricing.view', 'dashboard.view', 'report.view', 'report.tax',
                    'hr.employee.view', 'hr.salary.view', 'hr.payroll.approve', 'hr.documents.generate',
                    'wages.daily.manage', 'wages.daily.pay',
                ],
            ],
            self::SUPERVISOR => [
                'name' => 'Supervisor',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'approval.view', 'device.view',
                    'master.view', 'master.manage', 'sync.review',
                    'deal.view', 'gate.entry', 'weighment.capture', 'weighment.override', 'lab.test', 'lab.decide',
                    'production.view', 'production.log', 'batch.manage',
                    'stock.view', 'requisition.create', 'requisition.approve',
                    'sales.view', 'dispatch.manage', 'gatepass.issue',
                    'claim.view', 'claim.manage', 'dashboard.view', 'report.view', 'wages.daily.manage',
                    'hr.employee.view', 'hr.attendance.review', 'hr.leave.approve',
                ],
            ],
            self::INVENTORY_MANAGER => [
                'name' => 'Inventory Manager',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'master.view',
                    'production.view', 'stock.view', 'stock.adjust', 'requisition.create', 'requisition.approve',
                    'sales.view', 'dispatch.manage', 'report.view',
                ],
            ],
            self::LAB_TECHNICIAN => [
                'name' => 'Lab Technician',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'lab.test', 'production.view'],
            ],
            self::GATE_OPERATOR => [
                'name' => 'Weighbridge / Gate Operator',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'gate.entry', 'weighment.capture'],
            ],
            self::BROKER => [
                'name' => 'Broker / Agent',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['deal.view_own', 'commission.view_own', 'quote.manage'],
            ],
            self::HR_MANAGER => [
                'name' => 'HR Manager',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'approval.view', 'master.view', 'dashboard.view',
                    'hr.employee.view', 'hr.employee.edit', 'hr.employee.sensitive.view',
                    'hr.salary.view', 'hr.salary.edit', 'hr.payroll.run', 'hr.attendance.override', 'hr.attendance.review', 'hr.leave.approve',
                    'hr.documents.generate', 'hr.documents.share', 'hr.documents.template.manage', 'wages.daily.manage',
                ],
            ],
            self::EMPLOYEE => [
                'name' => 'Employee (attendance only)',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => [],
            ],
            self::OFFICE_COORDINATOR => [
                'name' => 'Office Coordinator',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 240,
                'permissions' => ['office.view', 'office.restore'],
            ],
        ];

        // Every staff role marks its own attendance and asks for its own leave (brokers are not employees; Owner / Super Admin
        // already hold every permission; the Office Coordinator has the Archive screen and nothing else).
        foreach ($templates as $code => $template) {
            if ($template['global'] || in_array($code, [self::BROKER, self::SUPER_ADMIN, self::OFFICE_COORDINATOR], true)) {
                continue;
            }
            foreach (['hr.attendance.punch', 'hr.leave.request'] as $own) {
                if (! in_array($own, $templates[$code]['permissions'], true)) {
                    $templates[$code]['permissions'][] = $own;
                }
            }
        }

        return $templates;
    }

    /** @return array{name: string, global: bool, mfa_required: bool, session_max_minutes: int, permissions: list<string>} */
    public static function get(string $code): array
    {
        return self::all()[$code] ?? throw new InvalidArgumentException("Unknown role template {$code}");
    }

    /** @return list<string> */
    public static function entityRoleCodes(): array
    {
        return array_keys(array_filter(self::all(), fn (array $t) => ! $t['global']));
    }
}
