<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Unit;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandAndUnitManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_brands()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/catalog/brands', ['name' => 'Samsung', 'is_active' => true])->assertSessionHasNoErrors();
        $brand = Brand::where('name', 'Samsung')->firstOrFail();

        $this->actingAs($admin)->put("/catalog/brands/{$brand->id}", ['name' => 'Samsung Electronics', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($brand->fresh()->is_active);

        $this->actingAs($admin)->post('/catalog/brands', ['name' => 'Samsung Electronics', 'is_active' => true])
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)->delete("/catalog/brands/{$brand->id}")->assertSessionHasNoErrors();
        $this->assertModelMissing($brand);
    }

    public function test_brand_in_use_cannot_be_deleted()
    {
        $brand = Brand::factory()->create();
        Product::factory()->create(['brand_id' => $brand->id]);

        $this->actingAs($this->admin())->delete("/catalog/brands/{$brand->id}")->assertSessionHasErrors('record');
        $this->assertModelExists($brand);
    }

    public function test_admin_can_manage_units_with_unique_names_and_short_names()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/catalog/units', ['name' => 'Piece', 'short_name' => 'pcs', 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/catalog/units', ['name' => 'Pieces', 'short_name' => 'pcs', 'is_active' => true])
            ->assertSessionHasErrors('short_name');
        $this->actingAs($admin)->post('/catalog/units', ['name' => 'Piece', 'short_name' => 'pc', 'is_active' => true])
            ->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/catalog/units', ['name' => 'Box', 'is_active' => true])
            ->assertSessionHasErrors('short_name');
    }

    public function test_unit_in_use_cannot_be_deleted()
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->delete("/catalog/units/{$product->unit_id}")->assertSessionHasErrors('record');
        $this->assertModelExists($product->unit);
    }

    public function test_brand_and_unit_lists_render_for_viewers()
    {
        Brand::factory()->create(['name' => 'Apple']);

        $this->actingAs($this->generalUser())->get('/catalog/brands')
            ->assertInertia(fn (Assert $page) => $page->component('catalog/brands')->where('records.data.0.name', 'Apple'));
        $this->actingAs($this->generalUser())->get('/catalog/units')
            ->assertInertia(fn (Assert $page) => $page->component('catalog/units'));
    }

    public function test_general_user_cannot_create_brands_or_units()
    {
        $user = $this->generalUser();

        $this->actingAs($user)->post('/catalog/brands', ['name' => 'X', 'is_active' => true])->assertForbidden();
        $this->actingAs($user)->post('/catalog/units', ['name' => 'X', 'short_name' => 'x', 'is_active' => true])->assertForbidden();
    }

    public function test_unit_seeder_is_idempotent()
    {
        $this->seed(UnitSeeder::class);
        $this->seed(UnitSeeder::class);

        $this->assertSame(4, Unit::count());
        $this->assertTrue(Unit::where('short_name', 'pcs')->exists());
    }
}
