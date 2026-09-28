<?php

namespace App\Models;

use App\Enums\OpeningBalanceType;
use App\Enums\PartyType;
use App\Models\Concerns\HasUserstamps;
use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier and/or customer. `balance` is a cache of the ledger written only by
 * App\Domain\PartyLedger\PartyLedgerService: positive = party owes the shop, negative = shop owes the party.
 */
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, HasUserstamps;

    protected $fillable = [
        'name',
        'type',
        'phone',
        'email',
        'address',
        'opening_balance',
        'opening_balance_type',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => PartyType::class,
            'opening_balance_type' => OpeningBalanceType::class,
            'opening_balance' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PartyLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(PartyLedgerEntry::class);
    }

    /**
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isSupplier(): bool
    {
        return $this->type->isSupplier();
    }

    /**
     * @param  Builder<Party>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Party>  $query
     */
    public function scopeSuppliers(Builder $query): void
    {
        $query->whereIn('type', PartyType::supplierValues());
    }
}
