#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Ui\Cli\CliHelper;
use App\Ui\Cli\Rename;
use App\Ui\Cli\Videos;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$cliHelper = new CliHelper();

$app = new Application();
$app->addCommands([
    new Videos(
        new Logger('app', [new StreamHandler('var/app.log', Level::Debug)]),
        $cliHelper,
    ),
    new Rename($cliHelper),
]);
$app->run();
