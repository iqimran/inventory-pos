<?php

namespace Tests\Feature\Sales;

use App\Enums\PartyType;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_barcode_lookup_returns_prices_and_stock()
    {
        $product = Product::factory()->withStock(7)->create(['barcode' => '8801643000011', 'retail_price' => '250.00', 'wholesale_price' => '200.00']);

        $this->actingAs($this->generalUser())->getJson('/pos/products/lookup/8801643000011')
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.stock', 7)
            ->assertJsonPath('data.retail_price', '250.00')
            ->assertJsonPath('data.wholesale_price', '200.00');
    }

    public function test_lookup_by_sku_and_unknown_code()
    {
        $product = Product::factory()->create(['sku' => 'CASE-01']);

        $this->actingAs($this->generalUser())->getJson('/pos/products/lookup/case-01')->assertOk()->assertJsonPath('data.id', $product->id);
        $this->actingAs($this->generalUser())->getJson('/pos/products/lookup/NOPE')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_product_search_excludes_inactive_products()
    {
        Product::factory()->create(['name' => 'Galaxy Case']);
        Product::factory()->inactive()->create(['name' => 'Galaxy Old']);

        $this->actingAs($this->generalUser())->getJson('/pos/products?q=galaxy')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Galaxy Case');
    }

    public function test_customer_search_finds_customers_by_name_or_phone_only()
    {
        Party::factory()->customer()->create(['name' => 'Karim Uddin', 'phone' => '01711000001']);
        Party::factory()->both()->create(['name' => 'Dual Dealer', 'phone' => '01811000002']);
        Party::factory()->supplier()->create(['name' => 'Karim Supplier', 'phone' => '01911000003']);
        Party::factory()->customer()->inactive()->create(['name' => 'Karim Inactive']);

        $this->actingAs($this->generalUser())->getJson('/pos/customers?q=karim')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Karim Uddin');
        $this->actingAs($this->generalUser())->getJson('/pos/customers?q=0181-1000')
            ->assertOk()->assertJsonPath('data.0.name', 'Dual Dealer');
    }

    public function test_cashier_can_quick_create_a_customer()
    {
        $this->actingAs($this->generalUser())->postJson('/pos/customers', ['name' => 'Rafiq', 'phone' => '+880 1711-222333'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rafiq')
            ->assertJsonPath('data.phone', '8801711222333');

        $party = Party::sole();
        $this->assertSame(PartyType::Customer, $party->type);
        $this->assertSame('0.00', $party->balance);

        $this->actingAs($this->generalUser())->postJson('/pos/customers', ['name' => 'Duplicate', 'phone' => '880-1711-222333'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_party_form_phones_are_normalised_for_lookup()
    {
        $this->actingAs($this->admin())->post('/parties', ['name' => 'Typed Phone', 'type' => 'CUSTOMER', 'phone' => '+880 (171) 555-6666', 'is_active' => true]);

        $this->assertSame('8801715556666', Party::sole()->phone);
        $this->actingAs($this->generalUser())->getJson('/pos/customers?q=5556666')->assertJsonPath('data.0.name', 'Typed Phone');
    }

    public function test_lookups_require_sales_permission()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/pos/products?q=a')->assertForbidden();
        $this->actingAs($user)->getJson('/pos/products/lookup/123')->assertForbidden();
        $this->actingAs($user)->getJson('/pos/customers?q=a')->assertForbidden();
        $this->actingAs($user)->postJson('/pos/customers', ['name' => 'X', 'phone' => '01700000000'])->assertForbidden();
    }
}
