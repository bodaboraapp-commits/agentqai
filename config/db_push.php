<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database push key
    |--------------------------------------------------------------------------
    |
    | Shared secret for the admin-only database push URL. When this is empty,
    | an authenticated admin session is the only requirement. When it is set,
    | the URL must carry "?key=<value>" (or "&key=<value>" when other query
    | parameters are present).
    |
    | A GET endpoint has no CSRF token, so a page someone else controls could
    | otherwise make a logged-in admin's browser trigger the push. Set this to
    | a long random string on installations where that matters.
    |
    */

    'key' => env('DB_PUSH_KEY'),

];
