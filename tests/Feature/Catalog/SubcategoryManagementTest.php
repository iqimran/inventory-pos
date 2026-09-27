<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SubcategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_subcategory_under_a_category()
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin())->post('/catalog/subcategories', [
            'category_id' => $category->id,
            'name' => 'Chargers',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($category->subcategories()->where('name', 'Chargers')->exists());
    }

    public function test_name_is_unique_within_a_category_only()
    {
        $first = Category::factory()->create();
        $second = Category::factory()->create();
        Subcategory::factory()->for($first)->create(['name' => 'Cases']);

        $this->actingAs($this->admin())->post('/catalog/subcategories', ['category_id' => $first->id, 'name' => 'Cases', 'is_active' => true])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->admin())->post('/catalog/subcategories', ['category_id' => $second->id, 'name' => 'Cases', 'is_active' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_inactive_category_cannot_receive_new_subcategories()
    {
        $category = Category::factory()->inactive()->create();

        $this->actingAs($this->admin())->post('/catalog/subcategories', [
            'category_id' => $category->id,
            'name' => 'Chargers',
            'is_active' => true,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_subcategory_with_products_cannot_move_to_another_category()
    {
        $subcategory = Subcategory::factory()->create();
        Product::factory()->create(['category_id' => $subcategory->category_id, 'subcategory_id' => $subcategory->id]);
        $other = Category::factory()->create();

        $this->actingAs($this->admin())->put("/catalog/subcategories/{$subcategory->id}", [
            'category_id' => $other->id,
            'name' => $subcategory->name,
            'is_active' => true,
        ])->assertSessionHasErrors('category_id');

        $this->assertNotSame($other->id, $subcategory->fresh()->category_id);
    }

    public function test_subcategories_can_be_filtered_by_category()
    {
        $category = Category::factory()->create();
        Subcategory::factory()->for($category)->create(['name' => 'Wanted']);
        Subcategory::factory()->create(['name' => 'Other']);

        $this->actingAs($this->admin())->get("/catalog/subcategories?category_id={$category->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/subcategories')
                ->has('records.data', 1)
                ->where('records.data.0.name', 'Wanted'));
    }

    public function test_subcategory_in_use_cannot_be_deleted_but_unused_can()
    {
        $used = Subcategory::factory()->create();
        Product::factory()->create(['category_id' => $used->category_id, 'subcategory_id' => $used->id]);
        $unused = Subcategory::factory()->create();

        $this->actingAs($this->admin())->delete("/catalog/subcategories/{$used->id}")->assertSessionHasErrors('record');
        $this->actingAs($this->admin())->delete("/catalog/subcategories/{$unused->id}")->assertSessionHasNoErrors();

        $this->assertModelExists($used);
        $this->assertModelMissing($unused);
    }
}
