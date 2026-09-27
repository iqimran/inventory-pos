<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Money moving between the shop and a party. OUT = paid to the party, IN = received from it.
         * allocated_amount caches SUM(payment_allocations.amount); the unallocated rest of an OUT
         * payment is the reconciliation pool for supplier advances.
         */
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 30)->unique();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3); // OUT | IN
            $table->string('purpose', 32);
            $table->string('method', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->string('reference_no', 100)->nullable();
            $table->dateTime('paid_at');
            $table->string('notes', 1000)->nullable();
            $table->nullableMorphs('source'); // document that created it (e.g. purchase, purchase return)
            $table->userstamps();
            $table->timestamps();

            $table->index(['party_id', 'direction', 'paid_at']);
            $table->index('paid_at');
        });

        /*
         * Which document(s) a payment settles. Polymorphic so later modules (sales) can reuse it.
         */
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->morphs('allocatable');
            $table->decimal('amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
