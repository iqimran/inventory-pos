<?php

namespace App\Enums;

/**
 * Catalogue of application permissions. Seeded by RolesAndPermissionsSeeder.
 *
 * Operational permissions for later modules are declared here so that roles
 * can be configured up-front; each module enforces them when it is built.
 */
enum Permission: string
{
    // Administration
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDeactivate = 'users.deactivate';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    // Products & inventory
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';

    // Parties & purchasing
    case PartiesView = 'parties.view';
    case PartiesManage = 'parties.manage';
    case PurchasesView = 'purchases.view';
    case PurchasesCreate = 'purchases.create';
    case PurchasesVoid = 'purchases.void';
    case PurchasesReturn = 'purchases.return';
    case PaymentsCreate = 'payments.create';
    case LedgerAdjust = 'ledger.adjust';

    // Sales & returns
    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesVoid = 'sales.void';
    case ReturnsCreate = 'returns.create';

    // Mobile service
    case ServiceView = 'service.view';
    case ServiceManage = 'service.manage';

    // Expenses
    case ExpensesView = 'expenses.view';
    case ExpensesManage = 'expenses.manage';

    // Barcode & reports
    case BarcodesPrint = 'barcodes.print';
    case ReportsView = 'reports.view';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'View users',
            self::UsersCreate => 'Create users',
            self::UsersUpdate => 'Update users',
            self::UsersDeactivate => 'Activate / deactivate users',
            self::RolesView => 'View roles',
            self::RolesManage => 'Manage roles & permissions',
            self::ProductsView => 'View products',
            self::ProductsManage => 'Manage products',
            self::InventoryView => 'View stock',
            self::InventoryAdjust => 'Adjust stock',
            self::PartiesView => 'View parties',
            self::PartiesManage => 'Manage parties',
            self::PurchasesView => 'View purchases',
            self::PurchasesCreate => 'Create purchases',
            self::PurchasesVoid => 'Void purchases',
            self::PurchasesReturn => 'Return purchases to supplier',
            self::PaymentsCreate => 'Record supplier payments & advances',
            self::LedgerAdjust => 'Manual party ledger adjustments',
            self::SalesView => 'View sales',
            self::SalesCreate => 'Create sales (POS)',
            self::SalesVoid => 'Void sales',
            self::ReturnsCreate => 'Process returns',
            self::ServiceView => 'View service jobs',
            self::ServiceManage => 'Manage service jobs',
            self::ExpensesView => 'View expenses',
            self::ExpensesManage => 'Manage expenses',
            self::BarcodesPrint => 'Print barcodes',
            self::ReportsView => 'View reports',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDeactivate,
            self::RolesView, self::RolesManage => 'Administration',
            self::ProductsView, self::ProductsManage, self::InventoryView, self::InventoryAdjust => 'Products & Inventory',
            self::PartiesView, self::PartiesManage, self::PurchasesView, self::PurchasesCreate, self::PurchasesVoid,
            self::PurchasesReturn, self::PaymentsCreate, self::LedgerAdjust => 'Parties & Purchasing',
            self::SalesView, self::SalesCreate, self::SalesVoid, self::ReturnsCreate => 'Sales & Returns',
            self::ServiceView, self::ServiceManage => 'Mobile Service',
            self::ExpensesView, self::ExpensesManage => 'Expenses',
            self::BarcodesPrint, self::ReportsView => 'Barcode & Reports',
        };
    }

    /**
     * Baseline operational permissions for the General User role.
     *
     * @return list<self>
     */
    public static function generalUserDefaults(): array
    {
        return [
            self::ProductsView,
            self::InventoryView,
            self::PartiesView,
            self::SalesView,
            self::SalesCreate,
            self::ServiceView,
            self::ServiceManage,
        ];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Permissions grouped for display: [{group, permissions: [{name, label}]}].
     *
     * @return list<array{group: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = ['name' => $permission->value, 'label' => $permission->label()];
        }

        return array_map(
            fn (string $group, array $permissions) => ['group' => $group, 'permissions' => $permissions],
            array_keys($groups),
            array_values($groups),
        );
    }
}
