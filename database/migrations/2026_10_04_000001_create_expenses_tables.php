<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->userstamps();
            $table->timestamps();
        });

        /*
         * Money spent by the shop. Financial history: never deleted — mistakes are voided with a reason.
         */
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no', 30)->unique();
            $table->foreignId('expense_type_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('status', 10)->default('RECORDED'); // RECORDED | VOID
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index(['status', 'expense_date']);
            $table->index(['expense_type_id', 'expense_date']);
            $table->index('expense_date');
        });

        /*
         * Who created, changed or voided an expense, and what changed (before → after).
         */
        Schema::create('expense_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained()->restrictOnDelete();
            $table->string('action', 10); // CREATED | UPDATED | VOIDED
            $table->json('changes')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['expense_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_audits');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_types');
    }
};
