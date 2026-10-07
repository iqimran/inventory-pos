<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Service / labour charges billed on a POS sale (e.g. screen-guard fitting, software
         * installation). No stock; reported as SERVICE revenue, never as product revenue.
         */
        Schema::create('sale_service_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->string('description', 191);
            $table->decimal('amount', 15, 2);
            $table->decimal('discount_share', 15, 2)->default(0); // share of the invoice discount
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            // Service charges after their invoice-discount share (part of total).
            $table->decimal('service_total', 15, 2)->default(0)->after('discount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('service_total');
        });

        Schema::dropIfExists('sale_service_charges');
    }
};
