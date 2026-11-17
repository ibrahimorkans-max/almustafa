<?php

// Generated from Filament's English file; edit the words in lang/ur.json's sources, not here.
return [
    'label' => 'تلاش بنانے والا',
    'form' => [
        'operator' => [
            'label' => 'آپریٹر',
        ],
        'or_groups' => [
            'label' => 'گروپ',
            'block' => [
                'label' => 'یا (OR)',
                'or' => 'یا',
            ],
        ],
        'rules' => [
            'label' => 'اصول',
            'item' => [
                'and' => 'اور',
            ],
        ],
    ],
    'no_rules' => '(کوئی اصول نہیں)',
    'item_separators' => [
        'and' => 'اور',
        'or' => 'یا',
    ],
    'operators' => [
        'is_filled' => [
            'label' => [
                'direct' => 'بھرا ہوا ہے',
                'inverse' => 'خالی ہے',
            ],
            'summary' => [
                'direct' => ':attribute بھرا ہوا ہے',
                'inverse' => ':attribute خالی ہے',
            ],
        ],
        'boolean' => [
            'is_true' => [
                'label' => [
                    'direct' => 'درست ہے',
                    'inverse' => 'غلط ہے',
                ],
                'summary' => [
                    'direct' => ':attribute درست ہے',
                    'inverse' => ':attribute غلط ہے',
                ],
            ],
        ],
        'date' => [
            'is_after' => [
                'label' => [
                    'direct' => 'کے بعد ہے',
                    'inverse' => 'کے بعد نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :date کے بعد ہے',
                    'inverse' => ':attribute، :date کے بعد نہیں ہے',
                ],
            ],
            'is_before' => [
                'label' => [
                    'direct' => 'سے پہلے ہے',
                    'inverse' => 'سے پہلے نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :date سے پہلے ہے',
                    'inverse' => ':attribute، :date سے پہلے نہیں ہے',
                ],
            ],
            'is_date' => [
                'label' => [
                    'direct' => 'تاریخ ہے',
                    'inverse' => 'تاریخ نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :date ہے',
                    'inverse' => ':attribute، :date نہیں ہے',
                ],
            ],
            'is_month' => [
                'label' => [
                    'direct' => 'مہینہ ہے',
                    'inverse' => 'مہینہ نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :month ہے',
                    'inverse' => ':attribute، :month نہیں ہے',
                ],
            ],
            'is_year' => [
                'label' => [
                    'direct' => 'سال ہے',
                    'inverse' => 'سال نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :year ہے',
                    'inverse' => ':attribute، :year نہیں ہے',
                ],
            ],
            'form' => [
                'date' => [
                    'label' => 'تاریخ',
                ],
                'month' => [
                    'label' => 'مہینہ',
                ],
                'year' => [
                    'label' => 'سال',
                ],
            ],
        ],
        'number' => [
            'equals' => [
                'label' => [
                    'direct' => 'برابر',
                    'inverse' => 'برابر نہیں',
                ],
                'summary' => [
                    'direct' => ':attribute، :number کے برابر ہے',
                    'inverse' => ':attribute، :number کے برابر نہیں',
                ],
            ],
            'is_max' => [
                'label' => [
                    'direct' => 'زیادہ سے زیادہ ہے',
                    'inverse' => 'سے زیادہ ہے',
                ],
                'summary' => [
                    'direct' => ':attribute زیادہ سے زیادہ :number ہے',
                    'inverse' => ':attribute، :number سے زیادہ ہے',
                ],
            ],
            'is_min' => [
                'label' => [
                    'direct' => 'کم از کم ہے',
                    'inverse' => 'سے کم ہے',
                ],
                'summary' => [
                    'direct' => ':attribute کم از کم :number ہے',
                    'inverse' => ':attribute، :number سے کم ہے',
                ],
            ],
            'aggregates' => [
                'average' => [
                    'label' => 'اوسط',
                    'summary' => 'اوسط :attribute',
                ],
                'max' => [
                    'label' => 'زیادہ سے زیادہ',
                    'summary' => 'زیادہ سے زیادہ :attribute',
                ],
                'min' => [
                    'label' => 'کم از کم',
                    'summary' => 'کم از کم :attribute',
                ],
                'sum' => [
                    'label' => 'مجموعہ',
                    'summary' => ':attribute کا مجموعہ',
                ],
            ],
            'form' => [
                'aggregate' => [
                    'label' => 'مجموعی',
                ],
                'number' => [
                    'label' => 'نمبر',
                ],
            ],
        ],
        'relationship' => [
            'equals' => [
                'label' => [
                    'direct' => 'موجود ہے',
                    'inverse' => 'موجود نہیں',
                ],
                'summary' => [
                    'direct' => ':count :relationship ہیں',
                    'inverse' => ':count :relationship نہیں ہیں',
                ],
            ],
            'has_max' => [
                'label' => [
                    'direct' => 'زیادہ سے زیادہ',
                    'inverse' => 'سے زیادہ ہیں',
                ],
                'summary' => [
                    'direct' => 'زیادہ سے زیادہ :count :relationship',
                    'inverse' => ':count سے زیادہ :relationship ہیں',
                ],
            ],
            'has_min' => [
                'label' => [
                    'direct' => 'کم از کم',
                    'inverse' => 'سے کم ہیں',
                ],
                'summary' => [
                    'direct' => 'کم از کم :count :relationship',
                    'inverse' => ':count سے کم :relationship ہیں',
                ],
            ],
            'is_empty' => [
                'label' => [
                    'direct' => 'خالی ہے',
                    'inverse' => 'خالی نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':relationship خالی ہے',
                    'inverse' => ':relationship خالی نہیں ہے',
                ],
            ],
            'is_related_to' => [
                'label' => [
                    'single' => [
                        'direct' => 'ہے',
                        'inverse' => 'نہیں ہے',
                    ],
                    'multiple' => [
                        'direct' => 'شامل ہے',
                        'inverse' => 'شامل نہیں',
                    ],
                ],
                'summary' => [
                    'single' => [
                        'direct' => ':relationship، :values ہے',
                        'inverse' => ':relationship، :values نہیں ہے',
                    ],
                    'multiple' => [
                        'direct' => ':relationship میں :values شامل ہے',
                        'inverse' => ':relationship میں :values شامل نہیں',
                    ],
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' یا ',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'قدر',
                    ],
                    'values' => [
                        'label' => 'اقدار',
                    ],
                ],
            ],
            'form' => [
                'count' => [
                    'label' => 'تعداد',
                ],
            ],
        ],
        'select' => [
            'is' => [
                'label' => [
                    'direct' => 'ہے',
                    'inverse' => 'نہیں ہے',
                ],
                'summary' => [
                    'direct' => ':attribute، :values ہے',
                    'inverse' => ':attribute، :values نہیں ہے',
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' یا ',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'قدر',
                    ],
                    'values' => [
                        'label' => 'اقدار',
                    ],
                ],
            ],
        ],
        'text' => [
            'contains' => [
                'label' => [
                    'direct' => 'شامل ہے',
                    'inverse' => 'شامل نہیں',
                ],
                'summary' => [
                    'direct' => ':attribute میں :text شامل ہے',
                    'inverse' => ':attribute میں :text شامل نہیں',
                ],
            ],
            'ends_with' => [
                'label' => [
                    'direct' => 'اس پر ختم ہوتا ہے',
                    'inverse' => 'اس پر ختم نہیں ہوتا',
                ],
                'summary' => [
                    'direct' => ':attribute، :text پر ختم ہوتا ہے',
                    'inverse' => ':attribute، :text پر ختم نہیں ہوتا',
                ],
            ],
            'equals' => [
                'label' => [
                    'direct' => 'برابر',
                    'inverse' => 'برابر نہیں',
                ],
                'summary' => [
                    'direct' => ':attribute، :text کے برابر ہے',
                    'inverse' => ':attribute، :text کے برابر نہیں',
                ],
            ],
            'starts_with' => [
                'label' => [
                    'direct' => 'اس سے شروع ہوتا ہے',
                    'inverse' => 'اس سے شروع نہیں ہوتا',
                ],
                'summary' => [
                    'direct' => ':attribute، :text سے شروع ہوتا ہے',
                    'inverse' => ':attribute، :text سے شروع نہیں ہوتا',
                ],
            ],
            'form' => [
                'text' => [
                    'label' => 'متن',
                ],
            ],
        ],
    ],
    'actions' => [
        'add_rule' => [
            'label' => 'اصول شامل کریں',
        ],
        'add_rule_group' => [
            'label' => 'اصولوں کا گروپ شامل کریں',
        ],
    ],
];
