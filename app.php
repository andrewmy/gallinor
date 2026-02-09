#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Images\Ui\Cli\MigrateAvifToHeic as ImagesMigrateAvifToHeic;
use App\Images\Ui\Cli\MigrateAvifToHeicWorker as ImagesMigrateAvifToHeicWorker;
use App\Images\Ui\Cli\RemoveAvifs as ImagesRemoveAvifs;
use App\Images\Ui\Cli\RemoveOriginals as ImagesRemoveOriginals;
use App\Images\Ui\Cli\Squeeze as ImagesSqueeze;
use App\Images\Ui\Cli\SqueezeWorker as ImagesSqueezeWorker;
use App\Shared\Infrastructure\NativePlatform;
use App\Shared\Infrastructure\SymfonyFilesystemScanner;
use App\Shared\Ui\Cli\CliHelper;
use App\Shared\Ui\Cli\Timing;
use App\Video\Domain\EncoderFactory;
use App\Video\Ui\Cli\Rename as VideosRename;
use App\Video\Ui\Cli\Squeeze as VideosSqueeze;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;

require __DIR__ . '/vendor/autoload.php';

$timing = Timing::start(microtime(true));

$logger    = new Logger('app', [new StreamHandler('var/app.log', Level::Debug)]);
$scanner   = new SymfonyFilesystemScanner(new Filesystem());
$cliHelper = new CliHelper();

$platform = new NativePlatform();

$timing = $timing->recordInit();

$ffmpegFactory = new EncoderFactory($platform);

$app = new Application();
$app->addCommands([
    new VideosSqueeze($logger, $cliHelper, $scanner, $timing, $ffmpegFactory, $platform),
    new VideosRename($cliHelper, $scanner, $timing),
    new ImagesSqueeze($cliHelper, $scanner, $timing, $platform, $logger),
    new ImagesSqueezeWorker($platform),
    new ImagesRemoveOriginals($logger, $cliHelper, $scanner, $timing, $platform),
    new ImagesMigrateAvifToHeic($cliHelper, $scanner, $timing, $platform, $logger),
    new ImagesMigrateAvifToHeicWorker($platform),
    new ImagesRemoveAvifs($cliHelper, $scanner, $timing),
]);
$app->run();
