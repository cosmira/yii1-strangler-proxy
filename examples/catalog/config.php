<?php

declare(strict_types=1);

return [
    'preload'       => ['log'],
    'basePath'      => __DIR__,
    'runtimePath'   => catalogRuntime(),
    'controllerMap' => [
        'catalog'     => ['class' => CatalogController::class],
        'catalogForm' => ['class' => CatalogController::class, 'inputFormat' => 'form'],
        'catalogform' => ['class' => CatalogController::class, 'inputFormat' => 'form'],
        'session'     => ['class' => SessionController::class],
        'apiError'    => ['class' => ApiErrorController::class],
    ],
    'components' => [
        'session'      => ['savePath' => catalogRuntime(), 'sessionName' => 'CATALOGSESSID'],
        'user'         => ['allowAutoLogin' => false],
        'errorHandler' => ['errorAction' => 'apiError/error'],
        'log'          => [
            'class'  => 'CLogRouter',
            'routes' => [['class' => 'CFileLogRoute', 'levels' => 'info, warning, error', 'categories' => 'strangler', 'logFile' => 'strangler.log']],
        ],
    ],
    'params' => [
        'strangler' => catalogSettings(),
    ],
];
