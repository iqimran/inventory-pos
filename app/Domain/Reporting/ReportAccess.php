<?php

namespace App\Domain\Reporting;

use App\Enums\Permission;
use App\Models\User;

/**
 * Who may see which report figures: reports.view plus the permission of the module whose data the
 * figure exposes. Holding reports.view alone never reveals another module's data (e.g. expenses).
 */
final class ReportAccess
{
    /** Report page (route name suffix) => required permissions (all). */
    public const REPORTS = [
        'sales' => [Permission::ReportsView, Permission::SalesView],
        'product-revenue' => [Permission::ReportsView, Permission::SalesView],
        'service-revenue' => [Permission::ReportsView, Permission::ServiceView],
        'revenue' => [Permission::ReportsView, Permission::SalesView, Permission::ServiceView],
        'stock' => [Permission::ReportsView, Permission::InventoryView],
        'parties' => [Permission::ReportsView, Permission::PartiesView],
    ];

    /** Dashboard section => required permissions (all). */
    public const DASHBOARD = [
        'product_sales' => [Permission::ReportsView, Permission::SalesView],
        'service_revenue' => [Permission::ReportsView, Permission::ServiceView],
        'combined_revenue' => [Permission::ReportsView, Permission::SalesView, Permission::ServiceView],
        'gross_profit' => [Permission::ReportsView, Permission::SalesView, Permission::PurchasesView],
        'purchases' => [Permission::ReportsView, Permission::PurchasesView],
        'expenses' => [Permission::ReportsView, Permission::ExpensesView],
        'outstanding' => [Permission::ReportsView, Permission::PartiesView],
        // Operational, not a report: anyone who may view stock sees what is running low.
        'low_stock' => [Permission::InventoryView],
    ];

    public static function report(User $user, string $report): bool
    {
        return isset(self::REPORTS[$report]) && self::all($user, self::REPORTS[$report]);
    }

    /**
     * @return array<string, bool> dashboard section => visible
     */
    public static function dashboard(User $user): array
    {
        return array_map(fn (array $permissions) => self::all($user, $permissions), self::DASHBOARD);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private static function all(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission->value)) {
                return false;
            }
        }

        return true;
    }
}
