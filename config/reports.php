<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reporting Time Zone
    |--------------------------------------------------------------------------
    |
    | Transactions are stored in the application time zone (usually UTC). Reports
    | group them into days and months in the shop's local time zone, so a sale
    | at 00:30 local time is reported on the day it happened.
    |
    */

    'timezone' => env('REPORT_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

];
