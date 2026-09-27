<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shop Details
    |--------------------------------------------------------------------------
    |
    | Printed on customer receipts.
    |
    */

    'name' => env('SHOP_NAME', env('APP_NAME', 'Mobile Shop')),
    'address' => env('SHOP_ADDRESS'),
    'phone' => env('SHOP_PHONE'),
    'receipt_footer' => env('SHOP_RECEIPT_FOOTER', 'Thank you for shopping with us.'),

    /*
    |--------------------------------------------------------------------------
    | Initial Administrator
    |--------------------------------------------------------------------------
    |
    | Used by the database seeder to create the first Admin account. A
    | password must be supplied outside of local/testing environments.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
