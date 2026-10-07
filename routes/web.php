<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Barcodes\BarcodeLabelController;
use App\Http\Controllers\Barcodes\ProductBarcodeController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\QuickProductController;
use App\Http\Controllers\Catalog\SubcategoryController;
use App\Http\Controllers\Catalog\UnitController;
use App\Http\Controllers\Expenses\ExpenseController;
use App\Http\Controllers\Expenses\ExpenseReportController;
use App\Http\Controllers\Expenses\ExpenseTypeController;
use App\Http\Controllers\Inventory\LowStockController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Parties\PartyController;
use App\Http\Controllers\Parties\PartyLedgerAdjustmentController;
use App\Http\Controllers\Purchasing\PurchaseController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Purchasing\SupplierPaymentController;
use App\Http\Controllers\Reports\DashboardController;
use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\RevenueReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Http\Controllers\Sales\CustomerPaymentController;
use App\Http\Controllers\Sales\PosController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Sales\SaleReturnController;
use App\Http\Controllers\Service\DeviceController;
use App\Http\Controllers\Service\ServiceInvoiceController;
use App\Http\Controllers\Service\ServiceJobChargeController;
use App\Http\Controllers\Service\ServiceJobController;
use App\Http\Controllers\Service\ServiceJobPartController;
use App\Http\Controllers\Service\ServiceJobStatusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Public: shown on the login page and as the browser tab icon.
Route::get('branding/logo', [BrandingController::class, 'logo'])->name('branding.logo');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Reports (reports.view). All revenue figures come from App\Domain\Reporting\RevenueReport.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('sales', SalesReportController::class)->name('sales');
        Route::get('product-revenue', [RevenueReportController::class, 'product'])->name('product-revenue');
        Route::get('service-revenue', [RevenueReportController::class, 'service'])->name('service-revenue');
        Route::get('revenue', [RevenueReportController::class, 'combined'])->name('revenue');
        Route::get('stock', StockReportController::class)->name('stock');
        Route::get('parties', PartyReportController::class)->name('parties');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::resource('roles', RoleController::class)->except(['show']);
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });

    // Catalogue master data (managed in dialogs on the index pages).
    Route::prefix('catalog')->group(function () {
        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('subcategories', SubcategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // Quick add from other workflows (registered before the resource so 'quick' is not read as a product id).
    Route::get('products/quick/options', [QuickProductController::class, 'options'])->name('products.quick.options');
    Route::post('products/quick', [QuickProductController::class, 'store'])->name('products.quick.store');
    Route::resource('products', ProductController::class);
    Route::post('products/{product}/barcode', [ProductBarcodeController::class, 'store'])->name('products.barcode.store');

    // Barcode labels: builder, then the printable sheet (GET so the print page can be reloaded/re-printed).
    Route::get('barcodes/labels', [BarcodeLabelController::class, 'create'])->name('barcodes.labels');
    Route::get('barcodes/labels/print', [BarcodeLabelController::class, 'print'])->name('barcodes.labels.print');

    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('movements', [StockMovementController::class, 'index'])->name('movements.index');
        Route::get('adjustments', [StockAdjustmentController::class, 'index'])->name('adjustments.index');
        Route::get('adjustments/create', [StockAdjustmentController::class, 'create'])->name('adjustments.create');
        Route::post('adjustments', [StockAdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('low-stock', [LowStockController::class, 'index'])->name('low-stock.index');
    });

    Route::resource('parties', PartyController::class);
    Route::post('parties/{party}/ledger-adjustments', [PartyLedgerAdjustmentController::class, 'store'])->name('parties.ledger-adjustments.store');

    // Purchases are immutable once recorded: no edit/delete. Corrections go through purchase returns.
    Route::resource('purchases', PurchaseController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('purchases/{purchase}/apply-advance', [PurchaseController::class, 'applyAdvance'])->name('purchases.apply-advance');
    Route::get('purchases/{purchase}/returns/create', [PurchaseReturnController::class, 'create'])->name('purchases.returns.create');
    Route::post('purchases/{purchase}/returns', [PurchaseReturnController::class, 'store'])->name('purchases.returns.store');
    Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');

    Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');
    Route::get('supplier-payments/create', [SupplierPaymentController::class, 'create'])->name('supplier-payments.create');
    Route::post('supplier-payments', [SupplierPaymentController::class, 'storePayment'])->name('supplier-payments.store');
    Route::post('supplier-advances', [SupplierPaymentController::class, 'storeAdvance'])->name('supplier-advances.store');
    Route::get('supplier-payments/{payment}', [SupplierPaymentController::class, 'show'])->name('supplier-payments.show');
    Route::get('supplier-payments/{payment}/print', [SupplierPaymentController::class, 'print'])->name('supplier-payments.print');

    // POS counter and its JSON lookups (session-authenticated, used while scanning).
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('products', [PosController::class, 'products'])->name('products');
        Route::get('products/lookup/{code}', [PosController::class, 'lookup'])->where('code', '[A-Za-z0-9\-._\/]+')->name('products.lookup');
        Route::get('customers', [PosController::class, 'customers'])->name('customers');
        Route::post('customers', [PosController::class, 'storeCustomer'])->name('customers.store');
    });

    // Sales are immutable once completed: no edit/delete. Corrections go through sale returns.
    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
    Route::get('sales/{sale}/returns/create', [SaleReturnController::class, 'create'])->name('sales.returns.create');
    Route::post('sales/{sale}/returns', [SaleReturnController::class, 'store'])->name('sales.returns.store');
    Route::get('sale-returns', [SaleReturnController::class, 'index'])->name('sale-returns.index');
    Route::get('sale-returns/{saleReturn}', [SaleReturnController::class, 'show'])->name('sale-returns.show');

    Route::get('customer-payments', [CustomerPaymentController::class, 'index'])->name('customer-payments.index');
    Route::get('customer-payments/create', [CustomerPaymentController::class, 'create'])->name('customer-payments.create');
    Route::post('customer-payments', [CustomerPaymentController::class, 'store'])->name('customer-payments.store');
    Route::get('customer-payments/{payment}', [CustomerPaymentController::class, 'show'])->name('customer-payments.show');

    // Expenses are never deleted: mistakes are voided (kept for audit, excluded from totals).
    Route::get('expenses/report', [ExpenseReportController::class, 'index'])->name('expenses.report');
    Route::resource('expense-types', ExpenseTypeController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['expense-types' => 'expenseType']);
    Route::resource('expenses', ExpenseController::class)->except(['destroy']);
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');

    // Mobile service. Jobs are never deleted (cancelled instead); invoices are immutable.
    Route::prefix('service')->group(function () {
        Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
        Route::put('devices/{device}', [DeviceController::class, 'update'])->name('devices.update');
        Route::get('customers/{party}/devices', [DeviceController::class, 'forCustomer'])->name('service.customer-devices');

        Route::resource('jobs', ServiceJobController::class)
            ->only(['index', 'create', 'store', 'show', 'update'])
            ->parameters(['jobs' => 'serviceJob'])
            ->names('service-jobs');

        Route::scopeBindings()->prefix('jobs/{serviceJob}')->name('service-jobs.')->group(function () {
            Route::post('status', [ServiceJobStatusController::class, 'store'])->name('status');
            Route::post('parts', [ServiceJobPartController::class, 'store'])->name('parts.store');
            Route::put('parts/{item}', [ServiceJobPartController::class, 'update'])->name('parts.update');
            Route::delete('parts/{item}', [ServiceJobPartController::class, 'destroy'])->name('parts.destroy');
            Route::post('charges', [ServiceJobChargeController::class, 'store'])->name('charges.store');
            Route::put('charges/{charge}', [ServiceJobChargeController::class, 'update'])->name('charges.update');
            Route::delete('charges/{charge}', [ServiceJobChargeController::class, 'destroy'])->name('charges.destroy');
            Route::post('invoice', [ServiceInvoiceController::class, 'store'])->name('invoice.store');
        });

        Route::get('invoices', [ServiceInvoiceController::class, 'index'])->name('service-invoices.index');
        Route::get('invoices/{serviceInvoice}', [ServiceInvoiceController::class, 'show'])->name('service-invoices.show');
        Route::get('invoices/{serviceInvoice}/print', [ServiceInvoiceController::class, 'print'])->name('service-invoices.print');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
