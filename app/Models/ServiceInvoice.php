<?php

namespace App\Models;

use App\Enums\InvoiceLineType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Combined service bill: PRODUCT lines (parts, which moved stock) and SERVICE lines (labour).
 * Financial history: never deleted. paid_amount / due_amount / payment_status are caches
 * maintained by App\Domain\MobileService\ServiceInvoiceSettlement.
 */
class ServiceInvoice extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'invoice_no',
        'service_job_id',
        'party_id',
        'status',
        'invoiced_at',
        'subtotal',
        'discount',
        'total',
        'product_total',
        'service_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'payment_method',
        'cost_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'invoiced_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'product_total' => 'decimal:2',
            'service_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Service invoices cannot be deleted.'));
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return HasMany<ServiceInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ServiceInvoiceItem::class);
    }

    /**
     * @return HasMany<ServiceInvoiceItem, $this>
     */
    public function productLines(): HasMany
    {
        return $this->items()->where('line_type', InvoiceLineType::Product);
    }

    /**
     * @return HasMany<ServiceInvoiceItem, $this>
     */
    public function serviceLines(): HasMany
    {
        return $this->items()->where('line_type', InvoiceLineType::Service);
    }

    /**
     * @return MorphMany<PaymentAllocation, $this>
     */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }
}
