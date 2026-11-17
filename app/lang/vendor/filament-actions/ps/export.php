<?php

// Generated from Filament's English file; edit the words in lang/ps.json's sources, not here.
return [
    'label' => ':label صادر کړئ',
    'modal' => [
        'heading' => ':label صادر کړئ',
        'form' => [
            'columns' => [
                'label' => 'ستنې',
                'form' => [
                    'is_enabled' => [
                        'label' => ':column فعال',
                    ],
                    'label' => [
                        'label' => ':column نوم',
                    ],
                ],
            ],
        ],
        'actions' => [
            'export' => [
                'label' => 'صادر کړئ',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'title' => 'صادرول بشپړ شول',
            'actions' => [
                'download_csv' => [
                    'label' => '‎.csv ښکته کړئ',
                ],
                'download_xlsx' => [
                    'label' => '‎.xlsx ښکته کړئ',
                ],
            ],
        ],
        'max_rows' => [
            'title' => 'صادرول ډېر لوی دي',
            'body' => 'په یو وخت کې له 1 قطار زیات نشئ صادرولای.|په یو وخت کې له :count قطارونو زیات نشئ صادرولای.',
        ],
        'started' => [
            'title' => 'صادرول پیل شول',
            'body' => 'صادرول پیل شول، 1 قطار به په شاليد کې چمتو شي. د بشپړېدو پر مهال به د ښکته کولو لینک خبرتیا درته راشي.|صادرول پیل شول، :count قطارونه به په شاليد کې چمتو شي. د بشپړېدو پر مهال به د ښکته کولو لینک خبرتیا درته راشي.',
        ],
    ],
    'file_name' => 'export-:export_id-:model',
];
