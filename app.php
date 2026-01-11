#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\SymfonyFilesystemScanner;
use App\Ui\Cli\CliHelper;
use App\Ui\Cli\Images\RemoveOriginals as ImagesRemoveOriginals;
use App\Ui\Cli\Images\Squeeze as ImagesSqueeze;
use App\Ui\Cli\Videos\Rename as VideosRename;
use App\Ui\Cli\Videos\Squeeze as VideosSqueeze;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/vendor/autoload.php';

$logger    = new Logger('app', [new StreamHandler('var/app.log', Level::Debug)]);
$scanner   = new SymfonyFilesystemScanner(new Filesystem());
$cliHelper = new CliHelper($scanner);

$app = new Application();
$app->addCommands([
    new VideosSqueeze($logger, $cliHelper),
    new VideosRename($cliHelper),
    new ImagesSqueeze($logger, $cliHelper),
    new ImagesRemoveOriginals($logger, $cliHelper),
]);
$app->run();
