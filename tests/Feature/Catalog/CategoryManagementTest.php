<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_are_listed_with_counts()
    {
        $category = Category::factory()->create(['name' => 'Accessories']);
        Subcategory::factory()->count(2)->for($category)->create();
        Product::factory()->for($category)->create();

        $this->actingAs($this->generalUser())->get('/catalog/categories')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/categories')
                ->where('records.data.0.name', 'Accessories')
                ->where('records.data.0.subcategories_count', 2)
                ->where('records.data.0.products_count', 1));
    }

    public function test_admin_can_create_update_and_deactivate_a_category()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/catalog/categories', ['name' => '  Mobile Phones ', 'is_active' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $category = Category::where('name', 'Mobile Phones')->firstOrFail();
        $this->assertSame($admin->id, $category->created_by);

        $this->actingAs($admin)->put("/catalog/categories/{$category->id}", [
            'name' => 'Smartphones',
            'description' => 'Handsets',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $category->refresh();
        $this->assertSame('Smartphones', $category->name);
        $this->assertFalse($category->is_active);
    }

    public function test_category_name_must_be_unique()
    {
        Category::factory()->create(['name' => 'Accessories']);

        $this->actingAs($this->admin())->post('/catalog/categories', ['name' => 'Accessories', 'is_active' => true])
            ->assertSessionHasErrors('name');
    }

    public function test_unused_category_can_be_deleted()
    {
        $category = Category::factory()->create();

        $this->actingAs($this->admin())->delete("/catalog/categories/{$category->id}")->assertSessionHasNoErrors();

        $this->assertModelMissing($category);
    }

    public function test_category_in_use_cannot_be_deleted()
    {
        $withSubcategory = Subcategory::factory()->create()->category;
        $withProduct = Product::factory()->create()->category;

        $this->actingAs($this->admin())->delete("/catalog/categories/{$withSubcategory->id}")->assertSessionHasErrors('record');
        $this->actingAs($this->admin())->delete("/catalog/categories/{$withProduct->id}")->assertSessionHasErrors('record');

        $this->assertModelExists($withSubcategory);
        $this->assertModelExists($withProduct);
    }

    public function test_viewers_cannot_manage_categories()
    {
        $user = $this->generalUser();
        $category = Category::factory()->create();

        $this->actingAs($user)->post('/catalog/categories', ['name' => 'X', 'is_active' => true])->assertForbidden();
        $this->actingAs($user)->put("/catalog/categories/{$category->id}", ['name' => 'X', 'is_active' => true])->assertForbidden();
        $this->actingAs($user)->delete("/catalog/categories/{$category->id}")->assertForbidden();

        $this->assertModelExists($category);
    }

    public function test_users_without_product_permission_cannot_view_categories()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/catalog/categories')->assertForbidden();
    }
}
