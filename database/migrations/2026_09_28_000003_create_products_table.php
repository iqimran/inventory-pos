<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('name', 191)->index();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('description', 1000)->nullable();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('retail_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->userstamps();
            $table->timestamps();

            // Filtered listings: active products by category/subcategory, ordered by name.
            $table->index(['is_active', 'category_id', 'subcategory_id', 'name'], 'products_listing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
