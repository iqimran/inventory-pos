<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * An allocation without a payment settles a purchase from the supplier's opening
         * balance (an advance held before the party was added), not from a recorded payment.
         */
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable(false)->change();
        });
    }
};
