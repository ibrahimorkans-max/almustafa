<?php

declare(strict_types=1);

namespace App\Domain\Saas;

/**
 * The parts of the system a plan can include. A right (permission) belongs to at most one module; a
 * right of a module the customer's plan does not include is simply not held (LoginAccount::permissionCodes),
 * so its screens, buttons, reports and API calls go away together. Rights of no module (masters,
 * administration, security, dashboard and reports, Office Desk, sync) are in every plan.
 */
final class Modules
{
    /** module => [label, rights] */
    public const ALL = [
        'gate' => ['Gate, weighbridge & lab', [
            'gate.entry', 'weighment.capture', 'weighment.override', 'weighment.manual', 'weighment.review',
            'lab.test', 'lab.decide', 'lab.limits', 'purchase.negotiate', 'purchase.approve',
        ]],
        'procurement' => ['Procurement & deals', [
            'deal.view', 'deal.view_own', 'deal.create', 'deal.approve', 'quote.view', 'quote.manage', 'requirement.broadcast',
        ]],
        'stock' => ['Stock & production', [
            'stock.view', 'stock.adjust', 'requisition.create', 'requisition.approve', 'production.view', 'production.log', 'batch.manage',
        ]],
        'accounts' => ['Accounts & payments', [
            'ledger.view', 'ledger.post', 'voucher.reverse', 'payment.create', 'payment.approve', 'bank.view', 'period.lock', 'commission.view_own',
        ]],
        'sales' => ['Sales, dispatch & GST', [
            'sales.view', 'sales.order', 'dispatch.manage', 'gatepass.issue', 'report.tax',
        ]],
        'claims' => ['Claims & pricing', [
            'claim.view', 'claim.manage', 'claim.approve',
            'pricing.view', 'pricing.rate.edit', 'pricing.markup.set', 'pricing.margin.view', 'pricing.margin.edit',
        ]],
        'hr' => ['HR, payroll & daily wages', [
            'hr.employee.view', 'hr.employee.edit', 'hr.employee.sensitive.view', 'hr.salary.view', 'hr.salary.edit',
            'hr.payroll.run', 'hr.payroll.approve', 'hr.attendance.punch', 'hr.attendance.review', 'hr.attendance.override',
            'hr.leave.request', 'hr.leave.approve', 'hr.documents.generate', 'hr.documents.share', 'hr.documents.template.manage',
            'wages.daily.manage', 'wages.daily.pay',
        ]],
        'messages' => ['Party accounts & messages', ['messages.manage', 'portal.view_own']],
    ];

    /** @return array<string, string> module => label */
    public static function labels(): array
    {
        return array_map(fn (array $m) => $m[0], self::ALL);
    }

    /** The module a right belongs to (null: in every plan). */
    public static function of(string $permission): ?string
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::ALL as $module => [, $rights]) {
                foreach ($rights as $right) {
                    $index[$right] = $module;
                }
            }
        }

        return $index[$permission] ?? null;
    }

    /**
     * The rights left when only these modules are included.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function filter(array $codes, array $modules): array
    {
        return array_values(array_filter($codes, function (string $code) use ($modules): bool {
            $module = self::of($code);

            return $module === null || in_array($module, $modules, true);
        }));
    }
}
