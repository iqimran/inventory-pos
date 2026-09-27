<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Negative Stock Policy
    |--------------------------------------------------------------------------
    |
    | When false (the default), any stock-out movement that would take a
    | product's balance below zero is rejected.
    |
    */

    'allow_negative_stock' => (bool) env('INVENTORY_ALLOW_NEGATIVE_STOCK', false),

];
