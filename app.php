#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Ui\Cli\CliHelper;
use App\Ui\Cli\Images\Squeeze as ImagesSqueeze;
use App\Ui\Cli\Videos\Rename as VideosRename;
use App\Ui\Cli\Videos\Squeeze as VideosSqueeze;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$logger    = new Logger('app', [new StreamHandler('var/app.log', Level::Debug)]);
$cliHelper = new CliHelper();

$app = new Application();
$app->addCommands([
    new VideosSqueeze($logger, $cliHelper),
    new VideosRename($cliHelper),
    new ImagesSqueeze($logger, $cliHelper),
]);
$app->run();
