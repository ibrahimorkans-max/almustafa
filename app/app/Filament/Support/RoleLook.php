<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\RoleTemplates;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Role;

/**
 * How a person's role looks in the top bar: its name, two colours, an icon and how the icon moves (the truck drives
 * for the gate operator, the beaker bubbles for the lab, the crown shines for the Owner…). The avatar uses the same
 * colours (RoleAvatar). Motion stops for people who asked their computer for less of it.
 */
final class RoleLook
{
    /** role code => [label, from colour, to colour, heroicon, motion] */
    private const LOOKS = [
        RoleTemplates::OWNER => ['Owner', '#f59e0b', '#b45309', 'heroicon-s-star', 'shine'],
        RoleTemplates::SUPER_ADMIN => ['Super Admin', '#8b5cf6', '#5b21b6', 'heroicon-s-shield-check', 'pulse'],
        RoleTemplates::EXECUTIVE => ['Executive', '#6366f1', '#3730a3', 'heroicon-s-briefcase', 'float'],
        RoleTemplates::ACCOUNTANT => ['Accountant', '#10b981', '#047857', 'heroicon-s-calculator', 'tap'],
        RoleTemplates::FINANCE_MANAGER => ['Finance Manager', '#059669', '#064e3b', 'heroicon-s-banknotes', 'float'],
        RoleTemplates::SUPERVISOR => ['Supervisor', '#f97316', '#c2410c', 'heroicon-s-clipboard-document-check', 'wobble'],
        RoleTemplates::INVENTORY_MANAGER => ['Inventory Manager', '#14b8a6', '#0f766e', 'heroicon-s-cube', 'spin'],
        RoleTemplates::LAB_TECHNICIAN => ['Lab Technician', '#06b6d4', '#0e7490', 'heroicon-s-beaker', 'bubble'],
        RoleTemplates::LAB_SUPERVISOR => ['Lab Supervisor', '#0891b2', '#164e63', 'heroicon-s-beaker', 'bubble'],
        RoleTemplates::GATE_OPERATOR => ['Weighbridge / Gate Operator', '#eab308', '#a16207', 'heroicon-s-truck', 'drive'],
        RoleTemplates::BROKER => ['Broker', '#ec4899', '#9d174d', 'heroicon-s-arrows-right-left', 'sway'],
        RoleTemplates::HR_MANAGER => ['HR Manager', '#f43f5e', '#9f1239', 'heroicon-s-user-group', 'pulse'],
        RoleTemplates::EMPLOYEE => ['Employee', '#64748b', '#334155', 'heroicon-s-user', 'float'],
        RoleTemplates::PURCHASE_OFFICER => ['Purchase Officer', '#84cc16', '#4d7c0f', 'heroicon-s-shopping-cart', 'drive'],
        RoleTemplates::PURCHASE_MANAGER => ['Purchase Manager', '#65a30d', '#365314', 'heroicon-s-shopping-bag', 'float'],
        RoleTemplates::OFFICE_COORDINATOR => ['Office Coordinator', '#0ea5e9', '#0369a1', 'heroicon-s-building-office-2', 'float'],
        RoleTemplates::REPORT_VIEWER => ['Report Viewer', '#94a3b8', '#334155', 'heroicon-s-chart-bar', 'float'],
        RoleTemplates::PARTY => ['Party', '#a855f7', '#6b21a8', 'heroicon-s-identification', 'float'],
    ];

    /** @return array{label: string, from: string, to: string, icon: string, motion: string} */
    public static function of(?LoginAccount $user): array
    {
        if ($user === null) {
            return self::make(['Guest', '#94a3b8', '#475569', 'heroicon-s-user', 'float']);
        }
        if ($user->isPlatformAdmin()) {
            return self::make(['Product owner', '#0ea5e9', '#1e3a8a', 'heroicon-s-globe-alt', 'spin']);
        }
        $role = app(TenantContext::class)->runAsSystem(fn () => Role::query()->find($user->role_id, ['id', 'code', 'name']));
        $look = self::LOOKS[$role->code ?? ''] ?? [(string) ($role->name ?? 'Staff'), '#64748b', '#334155', 'heroicon-s-user-circle', 'float'];
        if (! isset(self::LOOKS[$role->code ?? '']) && $role !== null) {
            $look[0] = (string) $role->name;   // a mill's own role keeps its own name
        }

        return self::make($look);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}  $look
     * @return array{label: string, from: string, to: string, icon: string, motion: string}
     */
    private static function make(array $look): array
    {
        return ['label' => $look[0], 'from' => $look[1], 'to' => $look[2], 'icon' => $look[3], 'motion' => $look[4]];
    }
}
