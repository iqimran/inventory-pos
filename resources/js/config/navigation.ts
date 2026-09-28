import { type NavGroup } from '@/types';
import {
    ArrowLeftRight,
    Banknote,
    BarChart3,
    Barcode,
    BookOpen,
    Boxes,
    ClipboardList,
    Contact,
    CreditCard,
    FileBarChart,
    FileText,
    FolderTree,
    LayoutGrid,
    Package,
    PackageSearch,
    Receipt,
    Ruler,
    ScanBarcode,
    ShieldCheck,
    ShoppingCart,
    Smartphone,
    Tag,
    Tags,
    TrendingUp,
    TriangleAlert,
    Undo2,
    Users,
    Wallet,
    Wrench,
} from 'lucide-react';

/**
 * Application navigation. Items are hidden when the user lacks `permission`.
 * Feature modules add their entries here as they are implemented.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [{ title: 'Dashboard', url: '/dashboard', icon: LayoutGrid }],
    },
    {
        title: 'Sales',
        items: [
            { title: 'Point of sale', url: '/pos', icon: ScanBarcode, permission: 'sales.create' },
            { title: 'Sales', url: '/sales', icon: Receipt, permission: 'sales.view' },
            { title: 'Sale returns', url: '/sale-returns', icon: Undo2, permission: 'sales.view' },
            { title: 'Customer payments', url: '/customer-payments', icon: CreditCard, permission: 'sales.view' },
        ],
    },
    {
        title: 'Reports',
        items: [
            { title: 'Sales report', url: '/reports/sales', icon: FileBarChart, permission: 'reports.view' },
            { title: 'Revenue', url: '/reports/revenue', icon: TrendingUp, permission: 'reports.view' },
            { title: 'Stock report', url: '/reports/stock', icon: PackageSearch, permission: 'reports.view' },
            { title: 'Party ledger', url: '/reports/parties', icon: BookOpen, permission: 'reports.view' },
        ],
    },
    {
        title: 'Mobile service',
        items: [
            { title: 'Service jobs', url: '/service/jobs', icon: Wrench, permission: 'service.view' },
            { title: 'Devices', url: '/service/devices', icon: Smartphone, permission: 'service.view' },
            { title: 'Service invoices', url: '/service/invoices', icon: FileText, permission: 'service.view' },
        ],
    },
    {
        title: 'Expenses',
        items: [
            { title: 'Expenses', url: '/expenses', icon: Wallet, permission: 'expenses.view' },
            { title: 'Expense types', url: '/expense-types', icon: Tags, permission: 'expenses.view' },
            { title: 'Expense report', url: '/expenses/report', icon: BarChart3, permission: 'expenses.view' },
        ],
    },
    {
        title: 'Catalog',
        items: [
            { title: 'Products', url: '/products', icon: Package, permission: 'products.view' },
            { title: 'Categories', url: '/catalog/categories', icon: FolderTree, permission: 'products.view' },
            { title: 'Subcategories', url: '/catalog/subcategories', icon: Boxes, permission: 'products.view' },
            { title: 'Brands', url: '/catalog/brands', icon: Tag, permission: 'products.view' },
            { title: 'Units', url: '/catalog/units', icon: Ruler, permission: 'products.view' },
            { title: 'Barcode labels', url: '/barcodes/labels', icon: Barcode, permission: 'barcodes.print' },
        ],
    },
    {
        title: 'Inventory',
        items: [
            { title: 'Low stock', url: '/inventory/low-stock', icon: TriangleAlert, permission: 'inventory.view' },
            { title: 'Stock adjustments', url: '/inventory/adjustments', icon: ClipboardList, permission: 'inventory.view' },
            { title: 'Stock movements', url: '/inventory/movements', icon: ArrowLeftRight, permission: 'inventory.view' },
        ],
    },
    {
        title: 'Purchasing',
        items: [
            { title: 'Parties', url: '/parties', icon: Contact, permission: 'parties.view' },
            { title: 'Purchases', url: '/purchases', icon: ShoppingCart, permission: 'purchases.view' },
            { title: 'Purchase returns', url: '/purchase-returns', icon: Undo2, permission: 'purchases.view' },
            { title: 'Supplier payments', url: '/supplier-payments', icon: Banknote, permission: 'purchases.view' },
        ],
    },
    {
        title: 'Administration',
        items: [
            { title: 'Users', url: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Roles & Permissions', url: '/admin/roles', icon: ShieldCheck, permission: 'roles.view' },
        ],
    },
];
