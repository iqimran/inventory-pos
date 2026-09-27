<?php

namespace App\Enums;

enum PaymentDirection: string
{
    /** Money paid by the shop to the party. */
    case Out = 'OUT';

    /** Money received by the shop from the party. */
    case In = 'IN';
}
