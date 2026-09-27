<?php

namespace App\Enums;

enum PartyType: string
{
    case Supplier = 'SUPPLIER';
    case Customer = 'CUSTOMER';
    case Both = 'BOTH';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Customer => 'Customer',
            self::Both => 'Supplier & customer',
        };
    }

    public function isSupplier(): bool
    {
        return $this !== self::Customer;
    }

    public function isCustomer(): bool
    {
        return $this !== self::Supplier;
    }

    /**
     * Types that may act as a supplier.
     *
     * @return list<string>
     */
    public static function supplierValues(): array
    {
        return [self::Supplier->value, self::Both->value];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
