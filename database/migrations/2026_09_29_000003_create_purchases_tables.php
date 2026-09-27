<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no', 30)->unique();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date');
            $table->string('supplier_invoice_no', 64)->nullable();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            // Settlement caches maintained by the purchasing services.
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('returned_amount', 15, 2)->default(0);
            $table->decimal('due_amount', 15, 2)->default(0);
            $table->string('payment_status', 10)->index(); // PAID | PARTIAL | DUE
            $table->string('notes', 1000)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index(['party_id', 'purchase_date']);
            $table->index('purchase_date');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_subtotal', 15, 2);
            $table->decimal('discount_share', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2); // net of the header discount share
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->decimal('returned_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->index('product_id');
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->decimal('total', 15, 2);
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->string('reason', 500);
            $table->userstamps();
            $table->timestamps();

            $table->index(['party_id', 'return_date']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
