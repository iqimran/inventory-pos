<?php

namespace Tests\Feature\Printing;

use App\Models\Device;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServiceInvoice;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T037 — POS receipt printing; T038 — service invoice printing.
 */
class DocumentPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.name' => 'IQ Mobile', 'shop.address' => 'Dhanmondi', 'shop.phone' => '01700000000', 'shop.receipt_footer' => 'Thanks!']);
        $this->cashier = $this->generalUser();
    }

    private function sell(array $extra = [], array $product = [])
    {
        $product = Product::factory()->withStock(10)->create(['name' => 'Phone case', 'retail_price' => '300.00', ...$product]);

        return $this->actingAs($this->cashier)->post('/sales', array_merge([
            'sale_type' => 'RETAIL', 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'paid_amount' => '600.00', 'payment_method' => 'CASH',
        ], $extra));
    }

    private function decode(string $uri): string
    {
        return base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')));
    }

    public function test_receipt_has_shop_header_items_totals_and_a_scannable_invoice_barcode()
    {
        $this->sell([], ['sku' => 'CASE-1'])->assertSessionHasNoErrors();
        $sale = Sale::sole();

        $this->actingAs($this->cashier)->get("/sales/{$sale->id}/receipt")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sales/receipt')
                ->where('shop.name', 'IQ Mobile')
                ->where('shop.address', 'Dhanmondi')
                ->where('shop.phone', '01700000000')
                ->where('shop.receipt_footer', 'Thanks!')
                ->where('sale.data.invoice_no', $sale->invoice_no)
                ->where('sale.data.items.0.product.sku', 'CASE-1')
                ->where('sale.data.items.0.quantity', 2)
                ->where('sale.data.total', '600.00')
                ->where('invoiceBarcode', fn ($uri) => str_contains($this->decode($uri), "<desc>{$sale->invoice_no}</desc>"))
                ->where('autoPrint', false));
    }

    public function test_pos_can_open_the_print_dialog_after_the_sale()
    {
        $this->sell(['auto_print' => true])->assertRedirect('/sales/'.Sale::sole()->id.'/receipt?new=1&print=1');
        $this->sell(['auto_print' => false])->assertRedirect('/sales/'.Sale::latest('id')->first()->id.'/receipt?new=1');

        $this->actingAs($this->cashier)->get('/sales/'.Sale::first()->id.'/receipt?print=1')
            ->assertInertia(fn (Assert $page) => $page->where('autoPrint', true));
    }

    public function test_service_invoice_print_separates_parts_and_labor()
    {
        $invoice = $this->serviceInvoice();

        $this->actingAs($this->cashier)->get("/service/invoices/{$invoice->id}/print?print=1")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('service/invoices/print')
                ->where('shop.name', 'IQ Mobile')
                ->where('autoPrint', true)
                ->where('invoiceBarcode', fn ($uri) => str_contains($this->decode($uri), "<desc>{$invoice->invoice_no}</desc>"))
                ->has('invoice.data.items', 3)
                ->where('invoice.data.items.0.line_type', 'PRODUCT')
                ->where('invoice.data.items.0.description', 'Charging IC')
                ->where('invoice.data.items.1.line_type', 'PRODUCT')
                ->where('invoice.data.items.2.line_type', 'SERVICE')
                ->where('invoice.data.items.2.description', 'Repair / labour')
                ->where('invoice.data.product_total', '1000.00')
                ->where('invoice.data.service_total', '500.00')
                ->where('invoice.data.total', '1500.00'));
    }

    public function test_print_pages_need_view_access()
    {
        $this->sell();
        $sale = Sale::sole();
        $invoice = $this->serviceInvoice();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get("/sales/{$sale->id}/receipt")->assertForbidden();
        $this->actingAs($outsider)->get("/service/invoices/{$invoice->id}/print")->assertForbidden();
    }

    private function serviceInvoice(): ServiceInvoice
    {
        $customer = Party::factory()->customer()->create();
        $device = Device::factory()->create(['party_id' => $customer->id]);
        $ic = Product::factory()->withStock(5)->create(['name' => 'Charging IC', 'retail_price' => '800.00']);
        $connector = Product::factory()->withStock(5)->create(['name' => 'Connector', 'retail_price' => '200.00']);

        $this->actingAs($this->cashier)->post('/service/jobs', ['party_id' => $customer->id, 'device_id' => $device->id, 'complaint' => 'No charging']);
        $job = ServiceJob::latest('id')->first();
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/parts", ['product_id' => $ic->id, 'quantity' => 1]);
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/parts", ['product_id' => $connector->id, 'quantity' => 1]);
        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/charges", ['description' => 'Repair / labour', 'amount' => '500']);

        foreach ([['DIAGNOSING'], ['WAITING_FOR_APPROVAL', ['diagnosis' => 'IC']], ['IN_PROGRESS', ['approved_amount' => '1500']], ['READY']] as $step) {
            $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/status", ['status' => $step[0], ...($step[1] ?? [])])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->cashier)->post("/service/jobs/{$job->id}/invoice", ['paid_amount' => '1500', 'payment_method' => 'CASH'])->assertSessionHasNoErrors();

        return ServiceInvoice::latest('id')->first();
    }
}
