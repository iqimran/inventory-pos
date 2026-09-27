<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->index();
            $table->string('type', 10)->index(); // SUPPLIER | CUSTOMER | BOTH
            $table->string('phone', 30)->nullable()->index();
            $table->string('email', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->string('opening_balance_type', 10)->nullable(); // RECEIVABLE | PAYABLE
            // Cached running ledger balance (positive = party owes the shop, negative = shop owes the party).
            // Written only by PartyLedgerService; reconcilable from party_ledger_entries.
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->userstamps();
            $table->timestamps();
        });

        /*
         * Immutable party ledger. Exactly one of debit/credit is non-zero.
         * Debit increases what the party owes the shop; credit increases what the shop owes the party.
         */
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('entry_type', 32);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2);
            $table->nullableMorphs('reference');
            $table->string('description', 500)->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['party_id', 'occurred_at', 'id']);
            $table->index(['entry_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_ledger_entries');
        Schema::dropIfExists('parties');
    }
};
