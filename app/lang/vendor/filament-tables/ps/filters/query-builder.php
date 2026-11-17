<?php

// Generated from Filament's English file; edit the words in lang/ps.json's sources, not here.
return [
    'label' => 'د پوښتنې جوړونکی',
    'form' => [
        'operator' => [
            'label' => 'چلوونکی',
        ],
        'or_groups' => [
            'label' => 'ډلې',
            'block' => [
                'label' => 'یا (OR)',
                'or' => 'یا',
            ],
        ],
        'rules' => [
            'label' => 'قواعد',
            'item' => [
                'and' => 'او',
            ],
        ],
    ],
    'no_rules' => '(هیڅ قاعده نشته)',
    'item_separators' => [
        'and' => 'او',
        'or' => 'یا',
    ],
    'operators' => [
        'is_filled' => [
            'label' => [
                'direct' => 'ډک دی',
                'inverse' => 'خالي دی',
            ],
            'summary' => [
                'direct' => ':attribute ډک دی',
                'inverse' => ':attribute خالي دی',
            ],
        ],
        'boolean' => [
            'is_true' => [
                'label' => [
                    'direct' => 'سم دی',
                    'inverse' => 'ناسم دی',
                ],
                'summary' => [
                    'direct' => ':attribute سم دی',
                    'inverse' => ':attribute ناسم دی',
                ],
            ],
        ],
        'date' => [
            'is_after' => [
                'label' => [
                    'direct' => 'وروسته دی',
                    'inverse' => 'وروسته نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute له :date وروسته دی',
                    'inverse' => ':attribute له :date وروسته نه دی',
                ],
            ],
            'is_before' => [
                'label' => [
                    'direct' => 'مخکې دی',
                    'inverse' => 'مخکې نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute له :date مخکې دی',
                    'inverse' => ':attribute له :date مخکې نه دی',
                ],
            ],
            'is_date' => [
                'label' => [
                    'direct' => 'نېټه ده',
                    'inverse' => 'نېټه نه ده',
                ],
                'summary' => [
                    'direct' => ':attribute :date دی',
                    'inverse' => ':attribute :date نه دی',
                ],
            ],
            'is_month' => [
                'label' => [
                    'direct' => 'میاشت ده',
                    'inverse' => 'میاشت نه ده',
                ],
                'summary' => [
                    'direct' => ':attribute :month دی',
                    'inverse' => ':attribute :month نه دی',
                ],
            ],
            'is_year' => [
                'label' => [
                    'direct' => 'کال دی',
                    'inverse' => 'کال نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute :year دی',
                    'inverse' => ':attribute :year نه دی',
                ],
            ],
            'form' => [
                'date' => [
                    'label' => 'نېټه',
                ],
                'month' => [
                    'label' => 'میاشت',
                ],
                'year' => [
                    'label' => 'کال',
                ],
            ],
        ],
        'number' => [
            'equals' => [
                'label' => [
                    'direct' => 'برابر',
                    'inverse' => 'برابر نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute له :number سره برابر دی',
                    'inverse' => ':attribute له :number سره برابر نه دی',
                ],
            ],
            'is_max' => [
                'label' => [
                    'direct' => 'تر ټولو زیات دی',
                    'inverse' => 'زیات دی',
                ],
                'summary' => [
                    'direct' => ':attribute تر ټولو زیات :number دی',
                    'inverse' => ':attribute له :number څخه زیات دی',
                ],
            ],
            'is_min' => [
                'label' => [
                    'direct' => 'لږ تر لږه دی',
                    'inverse' => 'کم دی',
                ],
                'summary' => [
                    'direct' => ':attribute لږ تر لږه :number دی',
                    'inverse' => ':attribute له :number څخه کم دی',
                ],
            ],
            'aggregates' => [
                'average' => [
                    'label' => 'اوسط',
                    'summary' => 'اوسط :attribute',
                ],
                'max' => [
                    'label' => 'اعظمي',
                    'summary' => 'اعظمي :attribute',
                ],
                'min' => [
                    'label' => 'لږ تر لږه',
                    'summary' => 'لږ تر لږه :attribute',
                ],
                'sum' => [
                    'label' => 'ټولګه',
                    'summary' => 'د :attribute ټولګه',
                ],
            ],
            'form' => [
                'aggregate' => [
                    'label' => 'ټولیز',
                ],
                'number' => [
                    'label' => 'شمېره',
                ],
            ],
        ],
        'relationship' => [
            'equals' => [
                'label' => [
                    'direct' => 'لري',
                    'inverse' => 'نه لري',
                ],
                'summary' => [
                    'direct' => ':count :relationship لري',
                    'inverse' => ':count :relationship نه لري',
                ],
            ],
            'has_max' => [
                'label' => [
                    'direct' => 'تر ټولو زیات',
                    'inverse' => 'له دې زیات لري',
                ],
                'summary' => [
                    'direct' => 'تر ټولو زیات :count :relationship',
                    'inverse' => 'له :count څخه زیات :relationship لري',
                ],
            ],
            'has_min' => [
                'label' => [
                    'direct' => 'لږ تر لږه',
                    'inverse' => 'له دې کم لري',
                ],
                'summary' => [
                    'direct' => 'لږ تر لږه :count :relationship',
                    'inverse' => 'له :count څخه کم :relationship لري',
                ],
            ],
            'is_empty' => [
                'label' => [
                    'direct' => 'خالي دی',
                    'inverse' => 'خالي نه دی',
                ],
                'summary' => [
                    'direct' => ':relationship خالي دی',
                    'inverse' => ':relationship خالي نه دی',
                ],
            ],
            'is_related_to' => [
                'label' => [
                    'single' => [
                        'direct' => 'دی',
                        'inverse' => 'نه دی',
                    ],
                    'multiple' => [
                        'direct' => 'لري',
                        'inverse' => 'نه لري',
                    ],
                ],
                'summary' => [
                    'single' => [
                        'direct' => ':relationship :values دی',
                        'inverse' => ':relationship :values نه دی',
                    ],
                    'multiple' => [
                        'direct' => ':relationship کې :values شامل دي',
                        'inverse' => ':relationship کې :values نشته',
                    ],
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' یا ',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'ارزښت',
                    ],
                    'values' => [
                        'label' => 'ارزښتونه',
                    ],
                ],
            ],
            'form' => [
                'count' => [
                    'label' => 'شمېر',
                ],
            ],
        ],
        'select' => [
            'is' => [
                'label' => [
                    'direct' => 'دی',
                    'inverse' => 'نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute :values دی',
                    'inverse' => ':attribute :values نه دی',
                    'values_glue' => [
                        0 => ', ',
                        'final' => ' یا ',
                    ],
                ],
                'form' => [
                    'value' => [
                        'label' => 'ارزښت',
                    ],
                    'values' => [
                        'label' => 'ارزښتونه',
                    ],
                ],
            ],
        ],
        'text' => [
            'contains' => [
                'label' => [
                    'direct' => 'لري',
                    'inverse' => 'نه لري',
                ],
                'summary' => [
                    'direct' => ':attribute کې :text شامل دی',
                    'inverse' => ':attribute کې :text نشته',
                ],
            ],
            'ends_with' => [
                'label' => [
                    'direct' => 'په دې ختمیږي',
                    'inverse' => 'په دې نه ختمیږي',
                ],
                'summary' => [
                    'direct' => ':attribute په :text ختمیږي',
                    'inverse' => ':attribute په :text نه ختمیږي',
                ],
            ],
            'equals' => [
                'label' => [
                    'direct' => 'برابر',
                    'inverse' => 'برابر نه دی',
                ],
                'summary' => [
                    'direct' => ':attribute له :text سره برابر دی',
                    'inverse' => ':attribute له :text سره برابر نه دی',
                ],
            ],
            'starts_with' => [
                'label' => [
                    'direct' => 'په دې پیلیږي',
                    'inverse' => 'په دې نه پیلیږي',
                ],
                'summary' => [
                    'direct' => ':attribute په :text پیلیږي',
                    'inverse' => ':attribute په :text نه پیلیږي',
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
            'label' => 'قاعده ورزیات کړئ',
        ],
        'add_rule_group' => [
            'label' => 'د قواعدو ډله ورزیات کړئ',
        ],
    ],
];
