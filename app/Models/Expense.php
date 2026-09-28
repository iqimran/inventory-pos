<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Money spent by the shop. Written through App\Actions\Expenses; never deleted (void instead),
 * and every change is recorded in expense_audits.
 */
class Expense extends Model
{
    use HasUserstamps;

    protected $fillable = [
        'expense_no',
        'expense_type_id',
        'amount',
        'expense_date',
        'payment_method',
        'reference',
        'notes',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'payment_method' => PaymentMethod::class,
            'status' => ExpenseStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Expenses cannot be deleted; void them instead.'));
    }

    public function isVoid(): bool
    {
        return $this->status === ExpenseStatus::Void;
    }

    /**
     * Expenses that count towards totals (not voided).
     *
     * @param  Builder<Expense>  $query
     */
    public function scopeRecorded(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Recorded);
    }

    /**
     * @return BelongsTo<ExpenseType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class, 'expense_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<ExpenseAudit, $this>
     */
    public function audits(): HasMany
    {
        return $this->hasMany(ExpenseAudit::class);
    }
}
