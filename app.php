#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFileCollector;
use App\Images\Ui\Cli\RemoveOriginals as ImagesRemoveOriginals;
use App\Images\Ui\Cli\Squeeze as ImagesSqueeze;
use App\Shared\Domain\Platform;
use App\Shared\Infrastructure\SymfonyFilesystemScanner;
use App\Shared\Ui\Cli\CliHelper;
use App\Video\Ui\Cli\Rename as VideosRename;
use App\Video\Ui\Cli\Squeeze as VideosSqueeze;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/vendor/autoload.php';

$logger    = new Logger('app', [new StreamHandler('var/app.log', Level::Debug)]);
$scanner   = new SymfonyFilesystemScanner(new Filesystem());
$cliHelper = new CliHelper();

$platform           = new Platform();
$exiftool           = new Exiftool($platform);
$imageFileCollector = new ImageFileCollector($scanner, $exiftool);

$app = new Application();
$app->addCommands([
    new VideosSqueeze($logger, $cliHelper, $scanner),
    new VideosRename($cliHelper, $scanner),
    new ImagesSqueeze($logger, $cliHelper, $imageFileCollector),
    new ImagesRemoveOriginals($logger, $cliHelper, $scanner, $imageFileCollector),
]);
$app->run();
