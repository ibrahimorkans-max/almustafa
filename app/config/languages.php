<?php

// Languages a person can choose for the whole system (menu, forms, buttons, messages). The words come from
// lang/<code>.json (keyed by the English text) and lang/vendor/<package>/<code>/; a missing word shows in English.
return [
    'default' => env('APP_LOCALE', 'en'),

    'available' => [
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
        'ur' => ['name' => 'Urdu', 'native' => 'اردو', 'dir' => 'rtl'],
        'ps' => ['name' => 'Pashto', 'native' => 'پښتو', 'dir' => 'rtl'],
        'sd' => ['name' => 'Sindhi', 'native' => 'سنڌي', 'dir' => 'rtl'],
    ],

    // Remembered on this browser for the sign-in page (before anyone is signed in).
    'cookie' => 'locale',
];
