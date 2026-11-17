<?php

// Generated from Filament's English file; edit the words in lang/sd.json's sources, not here.
return [
    'label' => ':label امپورٽ ڪريو',
    'modal' => [
        'heading' => ':label امپورٽ ڪريو',
        'form' => [
            'file' => [
                'label' => 'فائل',
                'placeholder' => 'CSV فائل اپلوڊ ڪريو',
                'rules' => [
                    'duplicate_columns' => '{0} فائل ۾ هڪ کان وڌيڪ خالي ڪالم سرخي نه هجڻ گهرجي.|{1,*} فائل ۾ ورجايل ڪالم سرخيون نه هجڻ گهرجن: :columns.',
                ],
            ],
            'columns' => [
                'label' => 'ڪالم',
                'placeholder' => 'ڪالم چونڊيو',
            ],
        ],
        'actions' => [
            'download_example' => [
                'label' => 'نموني CSV فائل ڊائونلوڊ ڪريو',
            ],
            'import' => [
                'label' => 'امپورٽ ڪريو',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'title' => 'امپورٽ مڪمل',
            'actions' => [
                'download_failed_rows_csv' => [
                    'label' => 'ناڪام قطار جي معلومات ڊائونلوڊ ڪريو|ناڪام قطارن جي معلومات ڊائونلوڊ ڪريو',
                ],
            ],
        ],
        'max_rows' => [
            'title' => 'اپلوڊ ٿيل CSV فائل تمام وڏي آهي',
            'body' => 'هڪ وقت ۾ 1 کان وڌيڪ قطار امپورٽ نٿي ٿي سگهي.|هڪ وقت ۾ :count کان وڌيڪ قطارون امپورٽ نٿيون ٿي سگهن.',
        ],
        'started' => [
            'title' => 'امپورٽ شروع ٿي ويو',
            'body' => 'امپورٽ شروع ٿي ويو آهي، 1 قطار پس منظر ۾ تيار ٿيندي.|امپورٽ شروع ٿي ويو آهي، :count قطارون پس منظر ۾ تيار ٿينديون.',
        ],
    ],
    'example_csv' => [
        'file_name' => ':importer-example',
    ],
    'failure_csv' => [
        'file_name' => 'import-:import_id-:csv_name-failed-rows',
        'error_header' => 'خرابي',
        'system_error' => 'سسٽم جي خرابي، مهرباني ڪري مدد سان رابطو ڪريو.',
        'column_mapping_required_for_new_record' => ':attribute ڪالم فائل جي ڪنهن ڪالم سان نه ملايو ويو، پر نوان رڪارڊ ٺاهڻ لاءِ ضروري آهي.',
    ],
];
