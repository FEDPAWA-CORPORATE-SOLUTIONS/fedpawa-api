<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode démo — exposition de l'OTP dans la réponse API (tests e2e uniquement)
    |--------------------------------------------------------------------------
    |
    | Lorsque true et APP_DEBUG=true, le code OTP de signature est retourné
    | dans la réponse JSON de souscription. NE JAMAIS activer en production
    | sans APP_DEBUG=false.
    */
    'FEDPAWA_DEMO_MODE' => env('FEDPAWA_DEMO_MODE', false),

];
