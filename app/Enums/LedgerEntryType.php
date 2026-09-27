<?php

namespace App\Enums;

/**
 * Party ledger entry types. Sale/customer types are posted by the POS module when built.
 */
enum LedgerEntryType: string
{
    case OpeningBalance = 'OPENING_BALANCE';
    case Purchase = 'PURCHASE';
    case PurchasePayment = 'PURCHASE_PAYMENT';
    case SupplierAdvance = 'SUPPLIER_ADVANCE';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case SupplierRefund = 'SUPPLIER_REFUND';
    case Sale = 'SALE';
    case CustomerPayment = 'CUSTOMER_PAYMENT';
    case SaleReturn = 'SALE_RETURN';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Purchase => 'Purchase (payable)',
            self::PurchasePayment => 'Payment to supplier',
            self::SupplierAdvance => 'Advance to supplier',
            self::PurchaseReturn => 'Purchase return',
            self::SupplierRefund => 'Refund from supplier',
            self::Sale => 'Sale (receivable)',
            self::CustomerPayment => 'Payment from customer',
            self::SaleReturn => 'Sale return',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }
}
