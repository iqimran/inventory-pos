<?php

namespace Tests\Feature\Security;

use App\Actions\Expenses\SaveExpense;
use App\Actions\MobileService\ChangeServiceJobStatus;
use App\Actions\MobileService\CreateServiceInvoice;
use App\Actions\MobileService\CreateServiceJob;
use App\Actions\MobileService\SaveServiceJobCharge;
use App\Actions\MobileService\SaveServiceJobPart;
use App\Actions\Purchasing\CreatePurchase;
use App\Actions\Purchasing\CreatePurchaseReturn;
use App\Actions\Purchasing\RecordSupplierAdvance;
use App\Actions\Sales\CreateSale;
use App\Actions\Sales\CreateSaleReturn;
use App\Enums\PaymentMethod;
use App\Enums\ServiceJobStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExpenseType;
use App\Models\Party;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\Unit;
use App\Models\User;

/**
 * One real record for every route model binding, created through the real actions (as an Admin).
 */
final class RouteFixtures
{
    /**
     * @return array<string, int|string>
     */
    public static function create(): array
    {
        $admin = User::factory()->admin()->create();
        auth()->login($admin);

        $customer = Party::factory()->customer()->create();
        $supplier = Party::factory()->supplier()->create();
        $product = Product::factory()->withStock(50)->create(['retail_price' => '100.00', 'barcode' => 'FIX-1']);

        $purchase = app(CreatePurchase::class)->handle([
            'party_id' => $supplier->id, 'purchase_date' => today()->toDateString(), 'paid_amount' => '0', 'payment_method' => 'CASH',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '50.00']],
        ]);
        $purchaseReturn = app(CreatePurchaseReturn::class)->handle($purchase, [
            'return_date' => today()->toDateString(), 'reason' => 'x', 'items' => [['purchase_item_id' => $purchase->items()->first()->id, 'quantity' => 1]],
        ]);
        $advance = app(RecordSupplierAdvance::class)->handle($supplier, '100.00', PaymentMethod::Cash, today()->toDateString());

        $sale = app(CreateSale::class)->handle(['sale_type' => 'RETAIL', 'party_id' => $customer->id, 'paid_amount' => '100.00', 'payment_method' => 'CASH',
            'items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $saleReturn = app(CreateSaleReturn::class)->handle($sale, ['reason' => 'x', 'items' => [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 1]]]);

        $job = app(CreateServiceJob::class)->handle(['party_id' => $customer->id, 'device' => ['brand' => 'X', 'model' => 'Y'], 'complaint' => 'x']);
        $part = app(SaveServiceJobPart::class)->handle($job, ['product_id' => $product->id, 'quantity' => 1]);
        $charge = app(SaveServiceJobCharge::class)->handle($job, ['description' => 'Labour', 'amount' => '100']);
        $invoicedJob = app(CreateServiceJob::class)->handle(['party_id' => $customer->id, 'device_id' => $job->device_id, 'complaint' => 'y']);
        app(SaveServiceJobCharge::class)->handle($invoicedJob, ['description' => 'Labour', 'amount' => '100']);
        foreach ([ServiceJobStatus::Diagnosing, ServiceJobStatus::WaitingForApproval, ServiceJobStatus::InProgress, ServiceJobStatus::Ready] as $s) {
            app(ChangeServiceJobStatus::class)->handle($invoicedJob, $s, ['diagnosis' => 'd', 'approved_amount' => '1']);
        }
        $serviceInvoice = app(CreateServiceInvoice::class)->handle($invoicedJob, ['paid_amount' => '0', 'payment_method' => 'CASH']);

        $expense = app(SaveExpense::class)->handle(null, ['expense_type_id' => ExpenseType::factory()->create()->id, 'amount' => '10', 'expense_date' => today()->toDateString(), 'payment_method' => 'CASH']);
        $role = Role::create(['name' => 'Fixture role', 'guard_name' => 'web']);

        auth()->logout();

        return [
            'user' => $admin->id, 'role' => $role->id, 'category' => Category::factory()->create()->id, 'subcategory' => Subcategory::factory()->create()->id,
            'brand' => Brand::factory()->create()->id, 'unit' => Unit::factory()->create()->id, 'product' => $product->id, 'party' => $customer->id,
            'purchase' => $purchase->id, 'purchaseReturn' => $purchaseReturn->id, 'payment' => $advance->id, 'sale' => $sale->id, 'saleReturn' => $saleReturn->id,
            'code' => 'FIX-1', 'serviceJob' => $job->id, 'item' => $part->id, 'charge' => $charge->id, 'serviceInvoice' => $serviceInvoice->id,
            'device' => $job->device_id, 'expense' => $expense->id, 'expenseType' => $expense->expense_type_id,
        ];
    }
}
