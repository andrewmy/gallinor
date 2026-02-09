<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli;

use App\Images\Domain\AvifCodec;
use App\Images\Domain\CalculationSkipReason;
use App\Images\Domain\Exiftool;
use App\Images\Domain\FfmpegImageNormalizer;
use App\Images\Domain\HeicCodec;
use App\Images\Domain\ImageOptimizer;
use App\Images\Domain\LibAvifTools;
use App\Images\Domain\Ssimulacra2;
use App\Images\Domain\StrictMetadataVerifier;
use App\Images\Ui\Cli\Parallel\ParallelTempDirectoryManager;
use App\Shared\Domain\Platform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symplify\EasyParallel\Enum\Action;
use Symplify\EasyParallel\Enum\Content;
use Symplify\EasyParallel\Enum\ReactCommand;
use Throwable;

use function fclose;
use function feof;
use function fgets;
use function fwrite;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function putenv;
use function sprintf;
use function stream_socket_client;
use function trim;
use function usleep;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

#[AsCommand(name: 'images:migrate-avif-to-heic:worker', description: 'Internal worker for images:migrate-avif-to-heic parallel mode', hidden: true)]
final class MigrateAvifToHeicWorker extends Command
{
    private const int PROTOCOL_VERSION = 1;

    public function __construct(private readonly Platform $platform)
    {
        parent::__construct();
    }

    public function __invoke(
        OutputInterface $output,
        #[Option]
        int $port = 0,
        #[Option]
        string $identifier = '',
        #[Option(description: 'Recycle worker after N AVIF jobs')]
        int $workerMaxJobs = 50,
    ): int {
        if ($port <= 0 || $identifier === '' || $workerMaxJobs <= 0) {
            return self::FAILURE;
        }

        try {
            $optimizer = $this->createOptimizer();
            $avifCodec = new AvifCodec(LibAvifTools::fromPlatform($this->platform));
            $heicCodec = new HeicCodec($this->platform);
        } catch (Throwable) {
            return self::FAILURE;
        }

        $socket = @stream_socket_client(sprintf('tcp://127.0.0.1:%d', $port), timeout: 15);
        if (! is_resource($socket)) {
            return self::FAILURE;
        }

        $workerRoot = ParallelTempDirectoryManager::workerRoot($identifier);
        ParallelTempDirectoryManager::ensureDir($workerRoot);

        $this->setTempEnv($workerRoot);
        $this->writeMessage($socket, [
            ReactCommand::ACTION     => Action::HELLO,
            ReactCommand::IDENTIFIER => $identifier,
        ]);

        $processedJobs = 0;

        try {
            while (! feof($socket)) {
                $line = fgets($socket);
                if ($line === false) {
                    usleep(10_000);
                    continue;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $payload = json_decode($line, true);
                if (! is_array($payload)) {
                    continue;
                }

                $action = $payload[ReactCommand::ACTION] ?? null;
                if (! is_string($action) || $action !== Action::MAIN) {
                    continue;
                }

                $files = $payload[Content::FILES] ?? null;
                if (! is_array($files) || $files === [] || ! is_array($files[0])) {
                    continue;
                }

                $jobId          = $files[0]['jobId'] ?? null;
                $path           = $files[0]['path'] ?? null;
                $targetHeicPath = $files[0]['targetHeicPath'] ?? null;

                if (! is_string($jobId) || ! is_string($path) || ! is_string($targetHeicPath)) {
                    continue;
                }

                $jobTempDir = $workerRoot . DIRECTORY_SEPARATOR . $jobId;
                ParallelTempDirectoryManager::ensureDir($jobTempDir);
                $this->setTempEnv($jobTempDir);

                try {
                    $statusCallback = function (int $quality, float $score, int $savedBytes) use ($socket, $identifier, $jobId, $path): void {
                        $this->writeMessage($socket, [
                            ReactCommand::ACTION => Action::RESULT,
                            Content::RESULT      => [
                                'v'          => self::PROTOCOL_VERSION,
                                'type'       => 'status',
                                'workerId'   => $identifier,
                                'jobId'      => $jobId,
                                'path'       => $path,
                                'quality'    => $quality,
                                'score'      => $score,
                                'savedBytes' => $savedBytes,
                            ],
                        ]);
                    };

                    $outcome = $optimizer->migrateAvifToHeic($path, $targetHeicPath, $avifCodec, $heicCodec, $statusCallback);

                    if ($outcome instanceof CalculationSkipReason) {
                        $this->writeMessage($socket, [
                            ReactCommand::ACTION => Action::RESULT,
                            Content::RESULT      => [
                                'v'          => self::PROTOCOL_VERSION,
                                'type'       => 'result',
                                'workerId'   => $identifier,
                                'jobId'      => $jobId,
                                'path'       => $path,
                                'outcome'    => 'skipped',
                                'skipReason' => $outcome->value,
                            ],
                        ]);
                    } else {
                        $this->writeMessage($socket, [
                            ReactCommand::ACTION => Action::RESULT,
                            Content::RESULT      => [
                                'v'        => self::PROTOCOL_VERSION,
                                'type'     => 'result',
                                'workerId' => $identifier,
                                'jobId'    => $jobId,
                                'path'     => $path,
                                'outcome'  => 'processed',
                                'result'   => [
                                    'optimizedSize' => $outcome->optimizedSize,
                                    'originalSize'  => $outcome->originalSize,
                                ],
                            ],
                        ]);
                    }
                } catch (Throwable $exception) {
                    $this->writeMessage($socket, [
                        ReactCommand::ACTION => Action::RESULT,
                        Content::RESULT      => [
                            'v'        => self::PROTOCOL_VERSION,
                            'type'     => 'result',
                            'workerId' => $identifier,
                            'jobId'    => $jobId,
                            'path'     => $path,
                            'outcome'  => 'error',
                            'error'    => $exception->getMessage(),
                        ],
                    ]);
                } finally {
                    $this->setTempEnv($workerRoot);
                    ParallelTempDirectoryManager::removeDir($jobTempDir);
                }

                $processedJobs++;
                if ($processedJobs >= $workerMaxJobs) {
                    break;
                }
            }
        } finally {
            fclose($socket);
            ParallelTempDirectoryManager::removeDir($workerRoot);
        }

        return self::SUCCESS;
    }

    private function createOptimizer(): ImageOptimizer
    {
        $exiftool   = new Exiftool($this->platform);
        $ssim       = Ssimulacra2::fromPlatform($this->platform);
        $normalizer = new FfmpegImageNormalizer($this->platform, $exiftool);
        $verifier   = new StrictMetadataVerifier();

        return new ImageOptimizer($ssim, $normalizer, $exiftool, $verifier);
    }

    /**
     * @param resource             $socket
     * @param array<string, mixed> $payload
     */
    private function writeMessage($socket, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        fwrite($socket, $json . "\n");
    }

    private function setTempEnv(string $tempRoot): void
    {
        putenv('TMPDIR=' . $tempRoot);
        putenv('TMP=' . $tempRoot);
        putenv('TEMP=' . $tempRoot);
    }
}
