<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A customer's handset. Customers are parties (CUSTOMER / BOTH).
         */
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->string('imei1', 20)->nullable();
            $table->string('imei2', 20)->nullable();
            $table->string('serial_no', 64)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index('imei1');
            $table->index('imei2');
            $table->index('serial_no');
        });

        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_no', 30)->unique();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('complaint', 2000);
            $table->string('diagnosis', 2000)->nullable();
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->decimal('approved_amount', 15, 2)->nullable();
            // Cache of SUM(service_job_charges.amount): labour/service revenue, never a stock movement.
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->string('status', 24);
            $table->dateTime('received_at');
            $table->dateTime('promised_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['party_id', 'received_at']);
            $table->index(['technician_id', 'status']);
            $table->index('received_at');
        });

        /*
         * Audit trail of every status change (who, when, from → to).
         */
        Schema::create('service_job_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['service_job_id', 'id']);
        });

        /*
         * Parts planned/used on a job. A draft until the job is invoiced: only then is stock
         * consumed (SERVICE_PART_OUT), the cost snapshotted and the line locked.
         */
        Schema::create('service_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('list_price', 15, 2); // product retail price when the part was added
            $table->decimal('unit_price', 15, 2); // price charged
            $table->boolean('price_overridden')->default(false);
            $table->decimal('line_total', 15, 2);
            $table->decimal('cost_snapshot', 15, 2)->nullable(); // unit cost at consumption
            $table->dateTime('consumed_at')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->userstamps();
            $table->timestamps();

            $table->unique(['service_job_id', 'product_id']);
            $table->index('product_id');
        });

        /*
         * Service / labour charge lines (SERVICE revenue).
         */
        Schema::create('service_job_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->decimal('amount', 15, 2);
            $table->userstamps();
            $table->timestamps();
        });

        /*
         * The customer's bill for a job: PRODUCT lines (parts) and SERVICE lines (labour) together.
         * Financial history: never deleted.
         */
        Schema::create('service_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique();
            $table->foreignId('service_job_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('COMPLETED');
            $table->dateTime('invoiced_at');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            // Net revenue per classification (after the discount share); product_total + service_total = total.
            $table->decimal('product_total', 15, 2)->default(0);
            $table->decimal('service_total', 15, 2)->default(0);
            // Settlement caches maintained by the service actions.
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('due_amount', 15, 2)->default(0);
            $table->string('payment_status', 10);
            $table->string('payment_method', 20)->nullable();
            $table->decimal('cost_total', 15, 2)->default(0); // parts cost snapshot
            $table->string('notes', 1000)->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index('invoiced_at');
            $table->index(['party_id', 'invoiced_at']);
            $table->index(['payment_status', 'invoiced_at']);
        });

        Schema::create('service_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained()->restrictOnDelete();
            $table->string('line_type', 10); // PRODUCT | SERVICE
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_job_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_job_charge_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal', 15, 2);
            $table->decimal('discount_share', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('unit_cost', 15, 2)->nullable(); // PRODUCT lines only
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['line_type', 'service_invoice_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_items');
        Schema::dropIfExists('service_invoices');
        Schema::dropIfExists('service_job_charges');
        Schema::dropIfExists('service_job_items');
        Schema::dropIfExists('service_job_status_logs');
        Schema::dropIfExists('service_jobs');
        Schema::dropIfExists('devices');
    }
};
