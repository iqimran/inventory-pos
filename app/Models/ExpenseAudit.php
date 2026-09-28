<?php

namespace App\Models;

use App\Enums\ExpenseAuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable record of an expense being created, edited (with before → after values) or voided.
 */
class ExpenseAudit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['expense_id', 'action', 'changes', 'reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'action' => ExpenseAuditAction::class,
            'changes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Expense audit entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Expense audit entries cannot be deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
