<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Stock is intentionally not an attribute of this model: it is derived from
 * stock_movements (see App\Domain\Inventory\StockService).
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'brand_id',
        'unit_id',
        'name',
        'sku',
        'barcode',
        'description',
        'purchase_price',
        'retail_price',
        'wholesale_price',
        'reorder_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Subcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return HasOne<ProductStock, $this>
     */
    public function stock(): HasOne
    {
        return $this->hasOne(ProductStock::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Current stock from the maintained balance (requires the `stock` relation).
     */
    public function stockQuantity(): int
    {
        return $this->stock?->quantity ?? 0;
    }

    /**
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
