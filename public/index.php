<?php
declare(strict_types=1);

use Tds\ApiGateway\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = Bootstrap::createApp(dirname(__DIR__));
$app->run();
