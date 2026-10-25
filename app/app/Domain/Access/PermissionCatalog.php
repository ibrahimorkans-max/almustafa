<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Single source of truth for permission codes. Synced into `permissions` by
 * `php artisan access:sync`, checked through Laravel's Gate (`can('deal.approve')`),
 * exported as JSON for the PWA / Flutter app / agent, and used to generate the
 * authorization test matrix.
 *
 * Sensitive permissions expose confidential (never falsified) figures such as
 * cost price, margin and broker spread.
 */
final class PermissionCatalog
{
    /** @var array<string, array{0: string, 1: string, 2?: bool}> code => [module, description, sensitive] */
    public const PERMISSIONS = [
        // Admin
        'entity.view' => ['admin', 'View entity (firm/mill) details'],
        'entity.manage' => ['admin', 'Create and edit entities'],
        'entity.edit_own' => ['admin', 'Edit the details of the own mill (name, NTN, STRN, address, phone)'],
        'user.view' => ['admin', 'View users and login accounts'],
        'user.manage' => ['admin', 'Create, disable, unlock and reset login accounts'],
        'role.view' => ['admin', 'View roles and permission grants'],
        'role.manage' => ['admin', 'Request changes to role permissions (maker)'],
        'device.view' => ['admin', 'View registered devices'],
        'device.manage' => ['admin', 'Revoke devices and request remote wipe'],
        'session.view_all' => ['admin', 'View all sessions in the entity'],
        'session.revoke_any' => ['admin', 'Revoke any session in the entity'],
        'audit.view' => ['admin', 'View the audit log'],
        'audit.verify' => ['admin', 'Run audit hash-chain verification'],
        'settings.manage' => ['admin', 'Change settings'],
        'approval.view' => ['admin', 'View maker-checker approval requests'],
        'approval.decide' => ['admin', 'Approve or reject requests made by someone else (checker)'],
        // Masters
        'master.view' => ['masters', 'View master data'],
        'master.manage' => ['masters', 'Create and edit master data'],
        // Sync
        'sync.review' => ['sync', 'Resolve sync conflicts in the supervisor review queue'],
        // Procurement
        'requirement.broadcast' => ['procurement', 'Broadcast paddy requirements'],
        'quote.view' => ['procurement', 'View quotes'],
        'quote.manage' => ['procurement', 'Record and negotiate quotes'],
        'deal.view' => ['procurement', 'View all deals (sauda)'],
        'portal.view_own' => ['procurement', 'Party login: see only its own vehicles, bills, payments and balance'],
        'messages.manage' => ['masters', 'Messages to parties: switch them on or off, see and send them, make party logins'],
        'deal.view_own' => ['procurement', 'View only own deals (broker/agent)'],
        'deal.create' => ['procurement', 'Create deals'],
        'deal.approve' => ['procurement', 'Approve deals'],
        // Gate, weighbridge, lab
        'gate.entry' => ['gate', 'Record gate entries'],
        'weighment.capture' => ['gate', 'Capture weighments from the weighbridge'],
        'weighment.override' => ['gate', 'Request manual weight override (reason + approval required)'],
        'weighment.manual' => ['gate', "Type one of a vehicle's two weights by hand (the other from the weighbridge), with a reason and the slip; flagged for the weight checks"],
        'weighment.review' => ['gate', 'Check recorded weights: mark OK or query them'],
        'wages.daily.manage' => ['hr', 'Daily wages: labour groups, work categories and wage sheets'],
        'wages.daily.pay' => ['hr', 'Daily wages: book wage sheets and pay them'],
        'lab.test' => ['gate', 'Record lab tests'],
        'lab.decide' => ['gate', 'Accept or reject a lot after lab test'],
        'lab.limits' => ['gate', 'Set the lab tests and their standard and grace limits'],
        'purchase.negotiate' => ['gate', 'Propose vehicle prices (negotiation) and the Caat and price settlement'],
        'purchase.approve' => ['gate', 'Approve negotiated prices, rejected-lot acceptance and vehicle settlements (not one\'s own)'],
        // Production
        'production.view' => ['production', 'View production'],
        'production.log' => ['production', 'Record production logs'],
        'batch.manage' => ['production', 'Open and close batches'],
        // Inventory
        'stock.view' => ['inventory', 'View stock'],
        'stock.adjust' => ['inventory', 'Request stock adjustments'],
        'requisition.create' => ['inventory', 'Create requisitions'],
        'requisition.approve' => ['inventory', 'Approve requisitions'],
        // Sales and dispatch
        'sales.view' => ['sales', 'View sales'],
        'sales.order' => ['sales', 'Create sales orders and deals'],
        'dispatch.manage' => ['sales', 'Manage dispatch and loading'],
        'gatepass.issue' => ['sales', 'Issue gate passes'],
        // Claims
        'claim.view' => ['claims', 'View claims'],
        'claim.manage' => ['claims', 'Raise and work on claims'],
        'claim.approve' => ['claims', 'Approve claim settlement'],
        // Accounts
        'ledger.view' => ['accounts', 'View ledgers and books'],
        'ledger.post' => ['accounts', 'Post vouchers'],
        'voucher.reverse' => ['accounts', 'Request voucher reversal'],
        'payment.create' => ['accounts', 'Create payments'],
        'payment.approve' => ['accounts', 'Approve payments'],
        'bank.view' => ['accounts', 'View bank book and bank details'],
        'period.lock' => ['accounts', 'Lock and unlock financial periods'],
        'commission.view_own' => ['accounts', 'View own commissions (broker/agent)'],
        // Pricing
        'pricing.view' => ['pricing', 'View rate cards and price lists'],
        'pricing.rate.edit' => ['pricing', 'Request rate changes'],
        'pricing.margin.view' => ['pricing', 'View cost price, margin and broker spread', true],
        'pricing.margin.edit' => ['pricing', 'Edit cost and margin fields', true],
        'pricing.markup.set' => ['pricing', 'Set markup percentages for new quotes and sales', true],
        // Reports
        'dashboard.view' => ['reports', 'View dashboards'],
        'report.view' => ['reports', 'View operational reports'],
        'report.tax' => ['reports', 'View and export sales-tax and withholding registers'],
        // Office Desk (closed records kept past the retention period; neutral wording on screens)
        'office.view' => ['office', 'Open the Office Desk and read its records'],
        'office.restore' => ['office', 'Restore records from the Office Desk (reason required)'],
        // HR and payroll
        'hr.employee.view' => ['hr', 'View employees (identifiers masked)'],
        'hr.employee.edit' => ['hr', 'Create and edit employees, documents and exits'],
        'hr.employee.sensitive.view' => ['hr', 'See full CNIC, NTN, bank account and date of birth (each reveal audited)', true],
        'hr.salary.view' => ['hr', 'View salary structures', true],
        'hr.salary.edit' => ['hr', 'Change salary structures (new effective-dated version)', true],
        'hr.payroll.run' => ['hr', 'Prepare payroll runs'],
        'hr.payroll.approve' => ['hr', 'Approve payroll runs and employee bank account changes (checker)'],
        'hr.attendance.override' => ['hr', 'Request manual attendance and corrections'],
        'hr.attendance.punch' => ['hr', 'Mark own attendance from own approved phone'],
        'hr.attendance.review' => ['hr', 'Accept or reject flagged attendance punches (with a reason)'],
        'hr.leave.request' => ['hr', 'Request own leave'],
        'hr.leave.approve' => ['hr', 'Approve, refuse or cancel leave of others'],
        'hr.documents.generate' => ['hr', 'Generate payslips and HR documents'],
        'hr.documents.share' => ['hr', 'Share HR documents (email, link, WhatsApp)'],
        'hr.documents.template.manage' => ['hr', 'Manage HR document templates'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::PERMISSIONS);
    }

    /** @return list<array{code: string, module: string, description: string, is_sensitive: bool}> */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::PERMISSIONS as $code => $def) {
            $rows[] = ['code' => $code, 'module' => $def[0], 'description' => $def[1], 'is_sensitive' => $def[2] ?? false];
        }

        return $rows;
    }
}
