<?php

// Generated from Filament's English file; edit the words in lang/ps.json's sources, not here.
return [
    'label' => ':label وارد کړئ',
    'modal' => [
        'heading' => ':label وارد کړئ',
        'form' => [
            'file' => [
                'label' => 'فایل',
                'placeholder' => 'CSV فایل پورته کړئ',
                'rules' => [
                    'duplicate_columns' => '{0} فایل باید له یوې زیاتې خالي ستنې سرلیک ونه لري.|{1,*} فایل باید تکراري ستنې سرلیکونه ونه لري: :columns.',
                ],
            ],
            'columns' => [
                'label' => 'ستنې',
                'placeholder' => 'ستنه وټاکئ',
            ],
        ],
        'actions' => [
            'download_example' => [
                'label' => 'د بېلګې CSV فایل ښکته کړئ',
            ],
            'import' => [
                'label' => 'وارد کړئ',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'title' => 'واردول بشپړ شول',
            'actions' => [
                'download_failed_rows_csv' => [
                    'label' => 'د ناکامې قطار معلومات ښکته کړئ|د ناکامو قطارونو معلومات ښکته کړئ',
                ],
            ],
        ],
        'max_rows' => [
            'title' => 'پورته شوی CSV فایل ډېر لوی دی',
            'body' => 'په یو وخت کې له 1 قطار زیات نشئ واردولای.|په یو وخت کې له :count قطارونو زیات نشئ واردولای.',
        ],
        'started' => [
            'title' => 'واردول پیل شول',
            'body' => 'واردول پیل شول، 1 قطار به په شاليد کې چمتو شي.|واردول پیل شول، :count قطارونه به په شاليد کې چمتو شي.',
        ],
    ],
    'example_csv' => [
        'file_name' => ':importer-example',
    ],
    'failure_csv' => [
        'file_name' => 'import-:import_id-:csv_name-failed-rows',
        'error_header' => 'تېروتنه',
        'system_error' => 'د سیسټم ستونزه، مهرباني وکړئ له ملاتړ سره اړیکه ونیسئ.',
        'column_mapping_required_for_new_record' => ':attribute ستنه د فایل له کومې ستنې سره نه ده تړل شوې، خو د نویو ریکارډونو لپاره اړینه ده.',
    ],
];
