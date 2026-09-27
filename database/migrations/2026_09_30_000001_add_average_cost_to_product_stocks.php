<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            // Moving weighted-average unit cost, maintained by StockService on costed stock-in.
            // Snapshotted onto stock-out movements and sale items for profit reporting.
            $table->decimal('average_cost', 15, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropColumn('average_cost');
        });
    }
};
