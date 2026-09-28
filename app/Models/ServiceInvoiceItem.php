<?php

namespace App\Models;

use App\Enums\InvoiceLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceInvoiceItem extends Model
{
    protected $fillable = [
        'service_invoice_id',
        'line_type',
        'product_id',
        'service_job_item_id',
        'service_job_charge_id',
        'description',
        'quantity',
        'unit_price',
        'line_subtotal',
        'discount_share',
        'line_total',
        'unit_cost',
        'cost_total',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => InvoiceLineType::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ServiceInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
