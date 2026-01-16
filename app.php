#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Images\Domain\ArchiveVerifier;
use App\Images\Domain\CqLevelCalculator;
use App\Images\Domain\Exiftool;
use App\Images\Domain\ImageFileCollector;
use App\Images\Domain\ImageProcessor;
use App\Images\Domain\ImageTools;
use App\Images\Domain\RawArchiver;
use App\Images\Ui\Cli\RemoveOriginals as ImagesRemoveOriginals;
use App\Images\Ui\Cli\Squeeze as ImagesSqueeze;
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

$platform           = new NativePlatform();
$exiftool           = new Exiftool($platform);
$imageFileCollector = new ImageFileCollector($scanner, $exiftool);
$archiveVerifier    = new ArchiveVerifier($platform);

$timing = $timing->recordInit();

$ffmpegFactory  = new EncoderFactory($platform);
$imageTools     = new ImageTools($platform);
$imageProcessor = new ImageProcessor(new CqLevelCalculator($imageTools));
$rawArchiver    = new RawArchiver($platform, $logger);

$app = new Application();
$app->addCommands([
    new VideosSqueeze($logger, $cliHelper, $scanner, $timing, $ffmpegFactory, $platform),
    new VideosRename($cliHelper, $scanner, $timing),
    new ImagesSqueeze($cliHelper, $imageFileCollector, $timing, $platform, $imageProcessor, $rawArchiver),
    new ImagesRemoveOriginals($logger, $cliHelper, $scanner, $imageFileCollector, $archiveVerifier, $timing),
]);
$app->run();
