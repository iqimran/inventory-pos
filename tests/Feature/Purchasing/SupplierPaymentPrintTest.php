<?php

namespace Tests\Feature\Purchasing;

use App\Enums\Permission;
use App\Models\Party;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupplierPaymentPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_payment_voucher_is_printable_with_the_organization_header()
    {
        config(['shop.name' => 'IQ Mobile', 'shop.address' => 'Dhanmondi, Dhaka', 'shop.phone' => '01700000000']);
        $admin = $this->admin();
        $supplier = Party::factory()->supplier()->create(['name' => 'Gadget Wholesale']);

        $this->actingAs($admin)->post('/supplier-advances', [
            'party_id' => $supplier->id, 'amount' => '5000.00', 'method' => 'BANK', 'date' => today()->toDateString(), 'reference_no' => 'TRX-77',
        ])->assertSessionHasNoErrors();
        $payment = Payment::sole();

        $this->actingAs($admin)->get("/supplier-payments/{$payment->id}/print")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier-payments/print')
                ->where('shop.name', 'IQ Mobile')
                ->where('shop.address', 'Dhanmondi, Dhaka')
                ->where('shop.phone', '01700000000')
                ->where('payment.data.payment_no', $payment->payment_no)
                ->where('payment.data.direction', 'OUT')
                ->where('payment.data.amount', '5000.00')
                ->where('payment.data.method', 'BANK')
                ->where('payment.data.reference_no', 'TRX-77')
                ->where('payment.data.party.name', 'Gadget Wholesale')
                ->where('payment.data.unallocated_amount', '5000.00'));
    }

    public function test_printing_needs_payment_access()
    {
        $supplier = Party::factory()->supplier()->create();
        $this->actingAs($this->admin())->post('/supplier-advances', [
            'party_id' => $supplier->id, 'amount' => '100', 'method' => 'CASH', 'date' => today()->toDateString(),
        ]);
        $payment = Payment::sole();

        $outsider = User::factory()->create();
        $outsider->givePermissionTo(Permission::SalesView->value);
        $this->actingAs($outsider)->get("/supplier-payments/{$payment->id}/print")->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::PurchasesView->value);
        $this->actingAs($viewer)->get("/supplier-payments/{$payment->id}/print")->assertOk();
    }
}
