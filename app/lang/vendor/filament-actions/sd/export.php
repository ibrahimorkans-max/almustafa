<?php

// Generated from Filament's English file; edit the words in lang/sd.json's sources, not here.
return [
    'label' => ':label ايڪسپورٽ ڪريو',
    'modal' => [
        'heading' => ':label ايڪسپورٽ ڪريو',
        'form' => [
            'columns' => [
                'label' => 'ڪالم',
                'form' => [
                    'is_enabled' => [
                        'label' => ':column فعال',
                    ],
                    'label' => [
                        'label' => ':column جو نالو',
                    ],
                ],
            ],
        ],
        'actions' => [
            'export' => [
                'label' => 'ايڪسپورٽ ڪريو',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'title' => 'ايڪسپورٽ مڪمل',
            'actions' => [
                'download_csv' => [
                    'label' => '‎.csv ڊائونلوڊ ڪريو',
                ],
                'download_xlsx' => [
                    'label' => '‎.xlsx ڊائونلوڊ ڪريو',
                ],
            ],
        ],
        'max_rows' => [
            'title' => 'ايڪسپورٽ تمام وڏو آهي',
            'body' => 'هڪ وقت ۾ 1 کان وڌيڪ قطار ايڪسپورٽ نٿي ٿي سگهي.|هڪ وقت ۾ :count کان وڌيڪ قطارون ايڪسپورٽ نٿيون ٿي سگهن.',
        ],
        'started' => [
            'title' => 'ايڪسپورٽ شروع ٿي ويو',
            'body' => 'ايڪسپورٽ شروع ٿي ويو آهي، 1 قطار پس منظر ۾ تيار ٿيندي. مڪمل ٿيڻ تي ڊائونلوڊ لنڪ جي اطلاع ملندي.|ايڪسپورٽ شروع ٿي ويو آهي، :count قطارون پس منظر ۾ تيار ٿينديون. مڪمل ٿيڻ تي ڊائونلوڊ لنڪ جي اطلاع ملندي.',
        ],
    ],
    'file_name' => 'export-:export_id-:model',
];
