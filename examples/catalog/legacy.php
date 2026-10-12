<?php

declare(strict_types=1);

define('YII_DEBUG', false);
define('CATALOG_EXECUTOR', 'legacy');
require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/vendor/yiisoft/yii/framework/yii.php';
require __DIR__.'/common.php';
require __DIR__.'/CatalogApiAdapter.php';
require __DIR__.'/proxy.php';
require __DIR__.'/controllers.php';

// This is a localhost demonstration, not an authentication system for production.
error_reporting(E_ALL & ~E_DEPRECATED);
ob_start();
$application = Yii::createWebApplication(require __DIR__.'/config.php');
$application->attachEventHandler('onEndRequest', static function (CEvent $event): void {
    catalogEvent('cleanup');
    if (! headers_sent()) {
        header('X-Cleanup-Count: 1');
    }
});
$application->run();
