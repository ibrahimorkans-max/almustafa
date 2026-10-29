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

    /** A supplier or buyer signing in to see its own vehicles, bills, payments and balance. */
    public const PARTY = 'PARTY';

    public const HR_MANAGER = 'HR_MANAGER';

    public const EMPLOYEE = 'EMPLOYEE';

    /** Approves or rejects lots within the grace limits (the lab technician records the results). */
    public const LAB_SUPERVISOR = 'LAB_SUPERVISOR';

    /** Enters the Caat and offered prices, negotiates rejected lots. */
    public const PURCHASE_OFFICER = 'PURCHASE_OFFICER';

    /** Approves negotiated prices, accepting rejected lots and vehicle settlements. */
    public const PURCHASE_MANAGER = 'PURCHASE_MANAGER';

    /** Approves financial postings, payment releases and corrections (never their own). */
    public const FINANCE_MANAGER = 'FINANCE_MANAGER';

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
                // The Office Desk (closed records past the retention period) is the Office Coordinator's alone.
                'permissions' => $except('deal.view_own', 'commission.view_own', 'portal.view_own', 'office.view', 'office.restore'),
            ],
            self::SUPER_ADMIN => [
                'name' => 'Owner',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => $except('entity.manage', 'deal.view_own', 'commission.view_own', 'portal.view_own', 'office.view', 'office.restore'),
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
                    'pricing.view', 'dashboard.view', 'report.view', 'report.export',
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
                    'ledger.view', 'ledger.post', 'voucher.reverse', 'payment.create', 'payment.approve', 'approval.decide', 'bank.view', 'messages.manage',
                    'pricing.view', 'dashboard.view', 'report.view', 'report.export', 'report.tax',
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
                    'deal.view', 'gate.entry', 'weighment.capture', 'weighment.override', 'weighment.manual', 'lab.test', 'lab.decide', 'purchase.negotiate',
                    'production.view', 'production.log', 'batch.manage',
                    'stock.view', 'requisition.create', 'requisition.approve',
                    'sales.view', 'dispatch.manage', 'gatepass.issue',
                    'claim.view', 'claim.manage', 'dashboard.view', 'report.view', 'report.export', 'wages.daily.manage',
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
                    'sales.view', 'dispatch.manage', 'report.view', 'report.export',
                ],
            ],
            self::LAB_TECHNICIAN => [
                'name' => 'Lab Technician',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'lab.test', 'production.view'],
            ],
            self::LAB_SUPERVISOR => [
                'name' => 'Lab Supervisor',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'lab.test', 'lab.decide', 'lab.limits', 'production.view'],
            ],
            self::PURCHASE_OFFICER => [
                'name' => 'Purchase Officer',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'deal.view', 'purchase.negotiate', 'pricing.view', 'report.view', 'report.export'],
            ],
            self::PURCHASE_MANAGER => [
                'name' => 'Purchase Manager',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => ['entity.view', 'master.view', 'deal.view', 'deal.create', 'deal.approve', 'approval.decide', 'purchase.negotiate', 'purchase.approve',
                    'pricing.view', 'report.view', 'report.export', 'dashboard.view', 'approval.view'],
            ],
            self::FINANCE_MANAGER => [
                'name' => 'Finance Manager',
                'global' => false,
                'mfa_required' => true,
                'session_max_minutes' => 720,
                'permissions' => [
                    'entity.view', 'approval.view', 'approval.decide', 'master.view',
                    'deal.view', 'stock.view', 'sales.view', 'claim.view', 'claim.approve', 'pricing.view',
                    'ledger.view', 'ledger.post', 'voucher.reverse', 'payment.create', 'payment.approve', 'bank.view', 'period.lock', 'messages.manage',
                    'dashboard.view', 'report.view', 'report.export', 'report.tax', 'purchase.approve', 'wages.daily.pay',
                ],
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
            self::PARTY => [
                'name' => 'Party (supplier / buyer)',
                'global' => false,
                'mfa_required' => false,
                'session_max_minutes' => 240,
                'permissions' => ['portal.view_own'],
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
            if ($template['global'] || in_array($code, [self::BROKER, self::PARTY, self::SUPER_ADMIN, self::OFFICE_COORDINATOR], true)) {
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
