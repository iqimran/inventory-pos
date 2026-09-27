<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settlement cache (like paid_amount/due_amount): total value returned against the sale.
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('returned_amount', 15, 2)->default(0)->after('paid_amount');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('COMPLETED');
            $table->dateTime('returned_at');
            $table->decimal('subtotal', 15, 2); // value of the goods returned
            $table->decimal('adjustment_amount', 15, 2)->default(0); // applied to the sale's outstanding due
            $table->decimal('refund_amount', 15, 2)->default(0); // cash paid back to the customer
            $table->decimal('credit_amount', 15, 2)->default(0); // left on the customer's account as store credit
            $table->string('refund_method', 20)->nullable();
            $table->string('reason', 500);
            $table->userstamps();
            $table->timestamps();

            $table->index('returned_at');
            $table->index(['party_id', 'returned_at']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2); // net price the customer paid per unit
            $table->decimal('amount', 15, 2);
            // Cost snapshot from the original sale line, for profit reversal.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('cost_total', 15, 2);
            $table->timestamps();

            $table->index('sale_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('returned_amount');
        });
    }
};
