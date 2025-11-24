#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Ui\Cli\Videos;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$app = new Application();
$app->add(
    new Videos(new Logger('app', [new StreamHandler('var/app.log', Level::Debug)])),
);
$app->run();
