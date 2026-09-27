<?php

namespace App\Enums;

enum PaymentPurpose: string
{
    case PurchasePayment = 'PURCHASE_PAYMENT';
    case SupplierAdvance = 'SUPPLIER_ADVANCE';
    case SupplierRefund = 'SUPPLIER_REFUND';
    case SalePayment = 'SALE_PAYMENT';
    case CustomerRefund = 'CUSTOMER_REFUND';

    public function label(): string
    {
        return match ($this) {
            self::PurchasePayment => 'Purchase payment',
            self::SupplierAdvance => 'Supplier advance',
            self::SupplierRefund => 'Supplier refund',
            self::SalePayment => 'Customer payment',
            self::CustomerRefund => 'Customer refund',
        };
    }

    public function direction(): PaymentDirection
    {
        return match ($this) {
            self::PurchasePayment, self::SupplierAdvance, self::CustomerRefund => PaymentDirection::Out,
            self::SupplierRefund, self::SalePayment => PaymentDirection::In,
        };
    }
}
