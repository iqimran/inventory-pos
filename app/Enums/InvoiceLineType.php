<?php

namespace App\Enums;

/**
 * Revenue classification of an invoice line. PRODUCT lines move stock; SERVICE lines never do.
 */
enum InvoiceLineType: string
{
    case Product = 'PRODUCT';
    case Service = 'SERVICE';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
        };
    }
}
