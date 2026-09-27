<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('sale_type', 10); // RETAIL | WHOLESALE
            $table->string('status', 12)->default('COMPLETED');
            $table->dateTime('sold_at');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('items_discount', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0); // invoice-level discount
            $table->decimal('total', 15, 2);
            // Settlement caches maintained by the sales actions.
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('due_amount', 15, 2)->default(0);
            $table->string('payment_status', 10); // PAID | PARTIAL | DUE
            $table->string('payment_method', 20)->nullable(); // method used at the counter
            $table->decimal('tendered_amount', 15, 2)->nullable();
            $table->decimal('change_amount', 15, 2)->default(0);
            // Cost snapshot total for gross-profit reporting.
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index('sold_at');
            $table->index(['party_id', 'sold_at']);
            $table->index(['sale_type', 'sold_at']);
            $table->index(['payment_status', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('list_price', 15, 2); // retail/wholesale price for the mode at sale time
            $table->decimal('unit_price', 15, 2); // price charged (differs from list_price when overridden)
            $table->boolean('price_overridden')->default(false);
            $table->decimal('line_subtotal', 15, 2);
            $table->decimal('line_discount', 15, 2)->default(0);
            $table->decimal('discount_share', 15, 2)->default(0); // share of the invoice discount
            $table->decimal('line_total', 15, 2);
            // Cost snapshot at sale time (moving average), never recalculated.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('cost_total', 15, 2);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
