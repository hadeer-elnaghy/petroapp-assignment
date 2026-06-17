<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Transfer Storage Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the storage driver used to ingest and query transfers.
    |
    | Supported: "database", "in_memory"
    |
    */
    'storage_driver' => env('TRANSFER_STORAGE_DRIVER', 'database'),
];
