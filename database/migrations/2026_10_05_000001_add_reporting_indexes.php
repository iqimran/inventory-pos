<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for report queries that are not already covered: purchase returns by date (dashboard
 * purchases), and party balances (outstanding receivables / payables).
 * Sales, service invoices, sale returns, stock movements, ledger entries and expenses are already
 * indexed on their date columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->index('return_date');
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->index('balance');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropIndex(['return_date']);
        });

        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['balance']);
        });
    }
};
