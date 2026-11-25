<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Cache Path
    |--------------------------------------------------------------------------
    |
    | Most view engines, including Blade, compile their templates into raw PHP
    | code which is then executed when rendering the view. This path defines
    | where these compiled views should be stored.
    |
    */

    'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),

];
