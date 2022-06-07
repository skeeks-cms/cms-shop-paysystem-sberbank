<?php
return [
    'components' => [
        'shop' => [
            'paysystemHandlers' => [
                'sberbank' => [
                    'class' => \skeeks\cms\shop\sberbank\SberbankPaysystemHandler::class
                ]
            ],
        ],

        'log' => [
            'targets' => [
                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['info', 'warning', 'error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\sberbank\SberbankPaysystemHandler::class, \skeeks\cms\shop\sberbank\controllers\SberbankController::class],
                    'logFile'    => '@runtime/logs/sberbank-info.log',
                ],

                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\sberbank\SberbankPaysystemHandler::class, \skeeks\cms\shop\sberbank\controllers\SberbankController::class],
                    'logFile'    => '@runtime/logs/sberbank-errors.log',
                ],
            ],
        ],
    ],
];