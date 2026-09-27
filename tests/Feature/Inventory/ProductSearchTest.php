<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\ProductSearch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private function search(array $filters): array
    {
        return app(ProductSearch::class)->query($filters)->pluck('name')->all();
    }

    public function test_barcode_matches_exactly()
    {
        Product::factory()->create(['name' => 'iPhone Cable', 'barcode' => '1234567890123']);
        Product::factory()->create(['name' => 'Other', 'barcode' => '1234567890999']);

        $this->assertSame(['iPhone Cable'], $this->search(['q' => '1234567890123']));
        $this->assertSame([], $this->search(['q' => '12345678901']));
    }

    public function test_sku_matches_exactly_and_case_insensitively()
    {
        Product::factory()->create(['name' => 'Charger', 'sku' => 'CHG-001']);

        $this->assertSame(['Charger'], $this->search(['q' => 'chg-001']));
    }

    public function test_name_matches_partially()
    {
        Product::factory()->create(['name' => 'Samsung Galaxy A15']);
        Product::factory()->create(['name' => 'Galaxy Buds']);
        Product::factory()->create(['name' => 'iPhone 15']);

        $this->assertSame(['Galaxy Buds', 'Samsung Galaxy A15'], $this->search(['q' => 'galaxy']));
    }

    public function test_exact_code_matches_rank_above_name_matches()
    {
        Product::factory()->create(['name' => 'A product mentioning X100', 'sku' => 'AAA-1']);
        Product::factory()->create(['name' => 'Zeta phone', 'sku' => 'X100']);

        $this->assertSame(['Zeta phone', 'A product mentioning X100'], $this->search(['q' => 'X100']));
    }

    public function test_like_wildcards_in_the_term_are_treated_literally()
    {
        Product::factory()->create(['name' => '100% Cotton Pouch']);
        Product::factory()->create(['name' => '1000 mAh Battery']);

        $this->assertSame(['100% Cotton Pouch'], $this->search(['q' => '100%']));
        $this->assertSame([], $this->search(['q' => '_']));
    }

    public function test_filters_by_category_subcategory_brand_and_status()
    {
        $subcategory = Subcategory::factory()->create();
        $brand = Brand::factory()->create();
        Product::factory()->create(['name' => 'Target', 'category_id' => $subcategory->category_id, 'subcategory_id' => $subcategory->id, 'brand_id' => $brand->id]);
        Product::factory()->create(['name' => 'Same category', 'category_id' => $subcategory->category_id]);
        Product::factory()->inactive()->create(['name' => 'Inactive']);

        $this->assertSame(['Same category', 'Target'], $this->search(['category_id' => $subcategory->category_id]));
        $this->assertSame(['Target'], $this->search(['subcategory_id' => $subcategory->id]));
        $this->assertSame(['Target'], $this->search(['brand_id' => $brand->id]));
        $this->assertSame(['Inactive'], $this->search(['status' => 'inactive']));
        $this->assertNotContains('Inactive', $this->search(['status' => 'active']));
    }

    public function test_listing_does_not_issue_per_row_queries()
    {
        Product::factory()->count(15)->create(['brand_id' => Brand::factory()]);
        $admin = $this->admin();

        DB::enableQueryLog();
        $this->actingAs($admin)->get('/products')->assertOk()->assertInertia(fn (Assert $page) => $page->has('products.data', 15));
        $queries = count(DB::getQueryLog());

        // Fixed number of queries regardless of row count (strict mode also forbids lazy loading).
        $this->assertLessThan(20, $queries);
    }

    public function test_product_index_page_applies_filters_and_paginates()
    {
        $category = Category::factory()->create();
        Product::factory()->count(25)->create(['category_id' => $category->id]);
        Product::factory()->create();

        $this->actingAs($this->generalUser())->get("/products?category_id={$category->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('products/index')
                ->has('products.data', 20)
                ->where('products.meta.total', 25)
                ->where('filters.category_id', $category->id));
    }

    public function test_api_search_returns_active_products_by_default()
    {
        Product::factory()->create(['name' => 'Active cable']);
        Product::factory()->inactive()->create(['name' => 'Inactive cable']);
        $token = $this->generalUser()->createToken('pos')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products?q=cable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active cable')
            ->assertJsonStructure(['data' => [['id', 'sku', 'barcode', 'retail_price', 'wholesale_price', 'stock']], 'meta']);
    }

    public function test_api_lookup_by_barcode_or_sku()
    {
        $product = Product::factory()->withStock(4)->create(['barcode' => '8801643000011', 'sku' => 'SKU-X']);
        $token = $this->generalUser()->createToken('pos')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products/lookup/8801643000011')
            ->assertOk()->assertJsonPath('data.id', $product->id)->assertJsonPath('data.stock', 4);
        $this->withToken($token)->getJson('/api/v1/products/lookup/sku-x')
            ->assertOk()->assertJsonPath('data.id', $product->id);
        $this->withToken($token)->getJson('/api/v1/products/lookup/0000000000000')->assertNotFound();
    }

    public function test_api_lookup_ignores_inactive_products()
    {
        Product::factory()->inactive()->create(['barcode' => '1111111111111']);
        $token = $this->generalUser()->createToken('pos')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products/lookup/1111111111111')->assertNotFound();
    }

    public function test_search_requires_product_view_permission()
    {
        $token = User::factory()->create()->createToken('pos')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/products')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/products/lookup/123')->assertForbidden();
    }
}
