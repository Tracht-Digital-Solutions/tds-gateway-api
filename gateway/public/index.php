<?php
declare(strict_types=1);

use Tds\ApiGateway\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = Bootstrap::createApp(dirname(__DIR__));

// Bring every bundled service's DB schema up to date on the first request after
// a deploy (guarded, once per migration-set, best-effort). Keeps the "install +
// run on Plesk without SSH" model from drifting behind new migrations.
Bootstrap::autoMigrate(dirname(__DIR__));

$app->run();
