<?php

// Generated from Filament's English file; edit the words in lang/sd.json's sources, not here.
return [
    'label' => 'ڳولا ٺاهيندڙ',
    'form' => [
        'operator' => [
            'label' => 'آپريٽر',
        ],
        'or_groups' => [
            'label' => 'گروپ',
            'block' => [
                'label' => 'يا (OR)',
                'or' => 'يا',
            ],
        ],
        'rules' => [
            'label' => 'اصول',
            'item' => [
                'and' => '۽',
            ],
        ],
    ],
    'no_rules' => '(ڪو اصول ناهي)',
    'item_separators' => [
        'and' => '۽',
        'or' => 'يا',
    ],
    'operators' => [
        'is_filled' => [
            'label' => [
                'direct' => 'ڀريل آهي',
                'inverse' => 'خالي آهي',
            ],
            'summary' => [
                'direct' => ':attribute ڀريل آهي',
                'inverse' => ':attribute خالي آهي',
            ],
        ],
        'boolean' => [
            'is_true' => [
                'label' => [
                    'direct' => 'صحيح آهي',
                    'inverse' => 'غلط آهي',
                ],
                'summary' => [
                    'direct' => ':attribute صحيح آهي',
                    'inverse' => ':attribute غلط آهي',
                ],
            ],
        ],
        'date' => [
            'is_after' => [
                'label' => [
                    'direct' => 'کان پوءِ آهي',
                    'inverse' => 'کان پوءِ ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute، :date کان پوءِ آهي',
                    'inverse' => ':attribute، :date کان پوءِ ناهي',
                ],
            ],
            'is_before' => [
                'label' => [
                    'direct' => 'کان اڳ آهي',
                    'inverse' => 'کان اڳ ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute، :date کان اڳ آهي',
                    'inverse' => ':attribute، :date کان اڳ ناهي',
                ],
            ],
            'is_date' => [
                'label' => [
                    'direct' => 'تاريخ آهي',
                    'inverse' => 'تاريخ ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute :date آهي',
                    'inverse' => ':attribute :date ناهي',
                ],
            ],
            'is_month' => [
                'label' => [
                    'direct' => 'مهينو آهي',
                    'inverse' => 'مهينو ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute :month آهي',
                    'inverse' => ':attribute :month ناهي',
                ],
            ],
            'is_year' => [
                'label' => [
                    'direct' => 'سال آهي',
                    'inverse' => 'سال ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute :year آهي',
                    'inverse' => ':attribute :year ناهي',
                ],
            ],
            'form' => [
                'date' => [
                    'label' => 'تاريخ',
                ],
                'month' => [
                    'label' => 'مهينو',
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
                    'inverse' => 'برابر ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute، :number جي برابر آهي',
                    'inverse' => ':attribute، :number جي برابر ناهي',
                ],
            ],
            'is_max' => [
                'label' => [
                    'direct' => 'وڌ ۾ وڌ آهي',
                    'inverse' => 'کان وڌيڪ آهي',
                ],
                'summary' => [
                    'direct' => ':attribute وڌ ۾ وڌ :number آهي',
                    'inverse' => ':attribute، :number کان وڌيڪ آهي',
                ],
            ],
            'is_min' => [
                'label' => [
                    'direct' => 'گهٽ ۾ گهٽ آهي',
                    'inverse' => 'کان گهٽ آهي',
                ],
                'summary' => [
                    'direct' => ':attribute گهٽ ۾ گهٽ :number آهي',
                    'inverse' => ':attribute، :number کان گهٽ آهي',
                ],
            ],
            'aggregates' => [
                'average' => [
                    'label' => 'اوسط',
                    'summary' => 'اوسط :attribute',
                ],
                'max' => [
                    'label' => 'وڌ ۾ وڌ',
                    'summary' => 'وڌ ۾ وڌ :attribute',
                ],
                'min' => [
                    'label' => 'گهٽ ۾ گهٽ',
                    'summary' => 'گهٽ ۾ گهٽ :attribute',
                ],
                'sum' => [
                    'label' => 'ڪل',
                    'summary' => ':attribute جو ڪل',
                ],
            ],
            'form' => [
                'aggregate' => [
                    'label' => 'مجموعي',
                ],
                'number' => [
                    'label' => 'نمبر',
                ],
            ],
        ],
        'relationship' => [
            'equals' => [
                'label' => [
                    'direct' => 'موجود آهي',
                    'inverse' => 'موجود ناهي',
                ],
                'summary' => [
                    'direct' => ':count :relationship آهن',
                    'inverse' => ':count :relationship ناهن',
                ],
            ],
            'has_max' => [
                'label' => [
                    'direct' => 'وڌ ۾ وڌ',
                    'inverse' => 'کان وڌيڪ آهن',
                ],
                'summary' => [
                    'direct' => 'وڌ ۾ وڌ :count :relationship',
                    'inverse' => ':count کان وڌيڪ :relationship آهن',
                ],
            ],
            'has_min' => [
                'label' => [
                    'direct' => 'گهٽ ۾ گهٽ',
                    'inverse' => 'کان گهٽ آهن',
                ],
                'summary' => [
                    'direct' => 'گهٽ ۾ گهٽ :count :relationship',
                    'inverse' => ':count کان گهٽ :relationship آهن',
                ],
            ],
            'is_empty' => [
                'label' => [
                    'direct' => 'خالي آهي',
                    'inverse' => 'خالي ناهي',
                ],
                'summary' => [
                    'direct' => ':relationship خالي آهي',
                    'inverse' => ':relationship خالي ناهي',
                ],
            ],
            'is_related_to' => [
                'label' => [
                    'single' => [
                        'direct' => 'آهي',
                        'inverse' => 'ناهي',
                    ],
                    'multiple' => [
                        'direct' => 'شامل آهي',
                        'inverse' => 'شامل ناهي',
                    ],
                ],
                'summary' => [
                    'single' => [
                        'direct' => ':relationship :values آهي',
                        'inverse' => ':relationship :values ناهي',
                    ],
                    'multiple' => [
                        'direct' => ':relationship ۾ :values شامل آهي',
                        'inverse' => ':relationship ۾ :values شامل ناهي',
                    ],
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' يا',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'قدر',
                    ],
                    'values' => [
                        'label' => 'قدر',
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
                    'direct' => 'آهي',
                    'inverse' => 'ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute :values آهي',
                    'inverse' => ':attribute :values ناهي',
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' يا',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'قدر',
                    ],
                    'values' => [
                        'label' => 'قدر',
                    ],
                ],
            ],
        ],
        'text' => [
            'contains' => [
                'label' => [
                    'direct' => 'شامل آهي',
                    'inverse' => 'شامل ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute ۾ :text شامل آهي',
                    'inverse' => ':attribute ۾ :text شامل ناهي',
                ],
            ],
            'ends_with' => [
                'label' => [
                    'direct' => 'ان تي ختم ٿئي ٿو',
                    'inverse' => 'ان تي ختم نٿو ٿئي',
                ],
                'summary' => [
                    'direct' => ':attribute، :text تي ختم ٿئي ٿو',
                    'inverse' => ':attribute، :text تي ختم نٿو ٿئي',
                ],
            ],
            'equals' => [
                'label' => [
                    'direct' => 'برابر',
                    'inverse' => 'برابر ناهي',
                ],
                'summary' => [
                    'direct' => ':attribute، :text جي برابر آهي',
                    'inverse' => ':attribute، :text جي برابر ناهي',
                ],
            ],
            'starts_with' => [
                'label' => [
                    'direct' => 'ان سان شروع ٿئي ٿو',
                    'inverse' => 'ان سان شروع نٿو ٿئي',
                ],
                'summary' => [
                    'direct' => ':attribute، :text سان شروع ٿئي ٿو',
                    'inverse' => ':attribute، :text سان شروع نٿو ٿئي',
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
            'label' => 'اصول شامل ڪريو',
        ],
        'add_rule_group' => [
            'label' => 'اصولن جو گروپ شامل ڪريو',
        ],
    ],
];
