<?php

// Generated from Filament's English file; edit the words in lang/ur.json's sources, not here.
return [
    'label' => ':label درآمد کریں',
    'modal' => [
        'heading' => ':label درآمد کریں',
        'form' => [
            'file' => [
                'label' => 'فائل',
                'placeholder' => 'CSV فائل اپ لوڈ کریں',
                'rules' => [
                    'duplicate_columns' => '{0} فائل میں ایک سے زیادہ خالی کالم سرخی نہیں ہونی چاہیے۔|{1,*} فائل میں دہرائی گئی کالم سرخیاں نہیں ہونی چاہئیں: :columns۔',
                ],
            ],
            'columns' => [
                'label' => 'کالم',
                'placeholder' => 'کالم منتخب کریں',
            ],
        ],
        'actions' => [
            'download_example' => [
                'label' => 'نمونہ CSV فائل ڈاؤن لوڈ کریں',
            ],
            'import' => [
                'label' => 'درآمد کریں',
            ],
        ],
    ],
    'notifications' => [
        'completed' => [
            'title' => 'درآمد مکمل',
            'actions' => [
                'download_failed_rows_csv' => [
                    'label' => 'ناکام قطار کی معلومات ڈاؤن لوڈ کریں|ناکام قطاروں کی معلومات ڈاؤن لوڈ کریں',
                ],
            ],
        ],
        'max_rows' => [
            'title' => 'اپ لوڈ کی گئی CSV فائل بہت بڑی ہے',
            'body' => 'ایک وقت میں 1 سے زیادہ قطار درآمد نہیں کی جا سکتی۔|ایک وقت میں :count سے زیادہ قطاریں درآمد نہیں کی جا سکتیں۔',
        ],
        'started' => [
            'title' => 'درآمد شروع ہو گئی',
            'body' => 'درآمد شروع ہو گئی ہے، 1 قطار پس منظر میں تیار ہوگی۔|درآمد شروع ہو گئی ہے، :count قطاریں پس منظر میں تیار ہوں گی۔',
        ],
    ],
    'example_csv' => [
        'file_name' => ':importer-example',
    ],
    'failure_csv' => [
        'file_name' => 'import-:import_id-:csv_name-failed-rows',
        'error_header' => 'خرابی',
        'system_error' => 'سسٹم کی خرابی، براہ کرم مدد سے رابطہ کریں۔',
        'column_mapping_required_for_new_record' => ':attribute کالم فائل کے کسی کالم سے نہیں ملایا گیا، مگر نئے ریکارڈ بنانے کے لیے یہ ضروری ہے۔',
    ],
];
