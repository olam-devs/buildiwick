<?php

namespace App\Support;

/**
 * What a user may do in the project ledger. Each role has defaults; the Director can tick or untick
 * any of them per user in User Management. Directors always have everything.
 */
class Access
{
    public const PERMISSIONS = [
        'record_expenses' => ['label' => 'Record expenses', 'hint' => 'Add expense lines on their projects'],
        'mark_paid' => ['label' => 'Confirm payments', 'hint' => 'Confirm "to confirm" lines and record lines as paid'],
        'deny_expenses' => ['label' => 'Deny expenses', 'hint' => 'Reject a line that should not count, with a reason'],
        'edit_any_line' => ['label' => 'Edit anyone\'s lines', 'hint' => 'Correct or delete any unpaid line, not only their own'],
        'manage_workers' => ['label' => 'Manage workers', 'hint' => 'Register workers and their contracts'],
        'manage_phases_budget' => ['label' => 'Edit phases & budget', 'hint' => 'Create phases and subphases and set budgets'],
        'export' => ['label' => 'Download Excel', 'hint' => 'Export the expenditure register'],
    ];

    public const ROLE_DEFAULTS = [
        'project_manager' => ['record_expenses', 'manage_workers', 'export'],
        'accounts' => ['record_expenses', 'mark_paid', 'deny_expenses', 'edit_any_line', 'export'],
        'accountant' => ['record_expenses', 'mark_paid', 'deny_expenses', 'edit_any_line', 'export'],
    ];

    /** Roles that see every project unless the Director limits them. */
    public const ALL_PROJECTS_BY_DEFAULT = ['director', 'admin', 'accounts', 'accountant'];

    /** @return array<int, string> */
    public static function defaultsFor(?string $role): array
    {
        if (in_array($role, ['director', 'admin'], true)) {
            return array_keys(self::PERMISSIONS);
        }

        return self::ROLE_DEFAULTS[$role] ?? [];
    }
}
