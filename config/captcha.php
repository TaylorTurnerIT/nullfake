<?php

return [
    'provider' => env('CAPTCHA_PROVIDER', 'recaptcha'), // 'recaptcha' or 'hcaptcha'

    'recaptcha' => [
        'site_key'   => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
    ],

    'hcaptcha' => [
        'site_key'   => env('HCAPTCHA_SITE_KEY'),
        'secret_key' => env('HCAPTCHA_SECRET_KEY'),
        'verify_url' => 'https://hcaptcha.com/siteverify',
    ],
];
