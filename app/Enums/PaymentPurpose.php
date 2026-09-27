<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case PurchasePayment = 'PURCHASE_PAYMENT';
    case SupplierAdvance = 'SUPPLIER_ADVANCE';
    case SupplierRefund = 'SUPPLIER_REFUND';
    case SalePayment = 'SALE_PAYMENT';

    public function label(): string
    {
        return match ($this) {
            self::PurchasePayment => 'Purchase payment',
            self::SupplierAdvance => 'Supplier advance',
            self::SupplierRefund => 'Supplier refund',
            self::SalePayment => 'Customer payment',
        };
    }

    public function direction(): PaymentDirection
    {
        return match ($this) {
            self::PurchasePayment, self::SupplierAdvance => PaymentDirection::Out,
            self::SupplierRefund, self::SalePayment => PaymentDirection::In,
        };
    }
}
