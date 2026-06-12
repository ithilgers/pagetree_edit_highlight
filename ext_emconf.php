<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PageTree Edit Highlight',
    'description' => 'Highlights pages in the backend page tree where the user has content editing permissions.',
    'category' => 'be',
    'author' => 'Theodor Hilgers',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'backend' => '12.4.0-12.4.99',
        ],
    ],
];
