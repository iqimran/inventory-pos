<?php

namespace App\Support\Validation;

use App\Enums\PartyType;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

final class MoneyRules
{
    public const MAX = '9999999999999.99';

    /** Upper bound for unit prices, keeps quantity × price inside DECIMAL(15,2). */
    public const MAX_UNIT = '99999999.99';

    /**
     * @return list<string>
     */
    public static function amount(bool $required = true, string $min = '0'): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'decimal:0,2', "min:{$min}", 'max:'.self::MAX];
    }

    /**
     * @return list<string>
     */
    public static function positive(): array
    {
        return self::amount(true, '0.01');
    }

    /**
     * True when the input is a numeric amount greater than zero (for conditional rules).
     */
    public static function isPositiveInput(mixed $value): bool
    {
        return is_numeric($value) && Money::isPositive(Money::of((string) $value));
    }

    public static function activeSupplier(): Exists
    {
        return Rule::exists('parties', 'id')->where('is_active', true)->whereIn('type', PartyType::supplierValues());
    }
}
