<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\SubcategoryController;
use App\Http\Controllers\Catalog\UnitController;
use App\Http\Controllers\Inventory\LowStockController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\Parties\PartyController;
use App\Http\Controllers\Parties\PartyLedgerAdjustmentController;
use App\Http\Controllers\Purchasing\PurchaseController;
use App\Http\Controllers\Purchasing\PurchaseReturnController;
use App\Http\Controllers\Purchasing\SupplierPaymentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::resource('roles', RoleController::class)->except(['show']);
    });

    // Catalogue master data (managed in dialogs on the index pages).
    Route::prefix('catalog')->group(function () {
        Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('subcategories', SubcategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('brands', BrandController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    Route::resource('products', ProductController::class);

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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
