<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Immutable stock ledger — the source of truth for stock.
         * quantity is signed: positive = stock in, negative = stock out.
         */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->nullableMorphs('reference');
            $table->string('reason', 32)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });

        /*
         * Running balance per product. Written only by StockService in the same
         * transaction as the movement; reconcilable from stock_movements at any time.
         */
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0)->index();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('stock_movements');
    }
};
