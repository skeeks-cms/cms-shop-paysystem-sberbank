<?php
return [
    'components' => [
        'shop' => [
            'paysystemHandlers' => [
                \skeeks\cms\shop\sberbank\SberbankPaysystemHandler::class
            ],
        ],
    ],
];