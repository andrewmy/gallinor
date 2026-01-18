# Parallel Image Processing Implementation Plan

## Summary
Add parallel JPEG processing to `images:squeeze` using Symfony Messenger with SQLite (WAL) and DB-backed resume.  
**Key Architecture Updates:**
- **Framework**: Add Symfony `Kernel` + `FrameworkBundle` (configuration via `App::config`).
- **Platform**: Enable Linux in `NativePlatform` for Docker images.
- **Database**: Use DoctrineBundle + Doctrine Messenger transport; schema is created by `images:squeeze` when parallelism is enabled.
- **Deployment**: Parallel execution is **Docker-only**; host mode remains sequential. Use named volume for SQLite to avoid bind-mount slowness.
- **Entrypoint**: `app.php` refactored to boot the Kernel and load config via Symfony.
- **Performance**: Workers process multiple media files concurrently.
- **Resume**: DB is the source of truth for completed jobs (even if `.avif` is missing).
- **Liveness**: Worker heartbeats stored in DB; main process detects dead workers without per-file timeouts.


## Initial Requirements
- **Dependencies**: `symfony/framework-bundle`, `symfony/messenger`, `symfony/doctrine-messenger`, `doctrine/doctrine-bundle`, `doctrine/dbal`, `symfony/config`, `symfony/dotenv`
- **Database**: SQLite with WAL mode at `var/messenger/gallinor-queue.db` (named Docker volume in production)
- **Concurrency**: Auto-detected based on CPU cores (`max(1, min(nCores/4, floor(nCores/8) + 2)`), overrideable via `--concurrency=N`
- **Progress tracking**: Poll `job_results` every 100ms for completions, update progress bar and display results
- **Cleanup**: Optional per-run cleanup (skip with `--keep-queue`); `messenger:cleanup` command; use `wal_checkpoint(TRUNCATE)` and attempt `VACUUM` only after workers stop
- **Retry strategy**: 2 retries with exponential backoff (1s, 2s, max 30s delay) and `redeliver_timeout` set above worst-case job time
- **Worker management**: Docker Compose handles worker replicas; no in-app subprocess management
- **Liveness**: Worker heartbeats stored in DB; main process exits if no live workers and queue still has jobs
- **Resume semantics**: DB is the source of truth (completed DB entry skips even if `.avif` is missing)
- **Platform support**: Deploy scripts for macOS/Linux (`.sh`) and Windows (`.bat`) using native tools, no PHP required
- **DB versioning**: Schema version checked on startup; mismatch => drop/recreate tables (resume DB is disposable)
- **Gallery path flexibility**: Deploy scripts support three invocation styles:
  - Command-line argument: `./deploy.sh /path/to/gallery`
  - Environment variable: `GALLINOR_GALLERY_PATH=/path/to/gallery ./deploy.sh`
  - Default (no argument): Uses `./gallery` (Linux/Mac) or `.\gallery` (Windows)
  - **Hybrid approach**: Priority order: CLI arg → ENV var → default
- **Testing**: Unit tests for queue/handler; integration tests use file-based SQLite (not `:memory:`) for multi-process support

## Overview
Implement Symfony Messenger-based parallel processing for `images:squeeze` with SQLite queue:
- **Host mode**: sequential only (no queue).
- **Docker mode**: parallel (queue + workers managed by Compose).

---

## Architecture

### Data Flow
```
┌─────────────────────────────────────────────────────────┐
│ images:squeeze --parallel (Main Process)              │
│  - Creates schema if parallel mode enabled            │
│  - Dispatches ProcessImageMessage for each JPEG       │
│  - Polls DB for completions (100ms interval)           │
│  - Uses worker heartbeats for liveness checks          │
│  - Updates progress bar, displays results             │
│  - Cleans up DB on completion                          │
└─────────────────────────────────────────────────────────┘
                          │
                          ▼
         ┌───────────────────────┐
         │ Symfony Messenger Bus │
         └───────────────────────┘
                          │
                          ▼
         ┌───────────────────────┐
         │ Doctrine Transport    │
         │ (SQLite + WAL)        │
         │ - messenger_messages  │
         │ - job_results         │
         │ - worker_heartbeats   │
         │ - schema_version      │
         └───────────────────────┘
                          ▲
                          │
         ┌──────────────────────────┐
         │ Docker workers (scaled)  │
         │ messenger:consume images │
         └──────────────────────────┘
```

### Modes
- **Host Mode**: Sequential processing only (no parallel workers).
- **Docker Mode**: Compose manages worker containers with dynamic replica count.

---

## Phase 1: Framework, Platform & Dependencies

### 1.1 Install Dependencies
```bash
composer require symfony/framework-bundle symfony/messenger symfony/doctrine-messenger doctrine/doctrine-bundle doctrine/dbal symfony/config symfony/dotenv
```

### 1.2 Enable Linux Support
Update `src/Shared/Infrastructure/NativePlatform.php`:
- Allow `Linux` (or detect via `PHP_OS_FAMILY`).
- Update `detectNCores()` to use `nproc` on Linux.
- Update `findTool()` to treat Linux same as macOS (standard `which` command).
- Determine the Linux `ssimulacra2` binary name inside the Docker image and hard-code mapping (expected: `ssimulacra2` or `ssimulacra2_rs`).

### 1.3 Database PRAGMA Configurator
Create `src/Shared/Infrastructure/DatabasePragmaConfigurator.php`:
```php
namespace App\Shared\Infrastructure;

use Doctrine\DBAL\Connection;

final class DatabasePragmaConfigurator
{
    public static function apply(Connection $connection): void
    {
        // Enable WAL mode for concurrency and performance
        $connection->executeStatement('PRAGMA journal_mode = WAL;');
        $connection->executeStatement('PRAGMA synchronous = NORMAL;');
        $connection->executeStatement('PRAGMA cache_size = -64000;');
        $connection->executeStatement('PRAGMA temp_store = memory;');
        $connection->executeStatement('PRAGMA busy_timeout = 10000;');  // 10 seconds for concurrent access
    }
}
```
Apply PRAGMAs in two places:
- `JobSchemaManager::ensureSchema()` before any queue reads/writes in parallel runs.
- `messenger:cleanup` before maintenance operations (`wal_checkpoint`, `VACUUM`).

### 1.4 Configuration Files

**`config/services.php`**
```php
namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'services' => [
        '_defaults' => [
            'autowire' => true,
            'autoconfigure' => true,
            'public' => true,
        ],

        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/DependencyInjection/',
                '../src/Entity/',
                '../src/Kernel.php',
            ],
        ],
    ],
]);
```

**`config/bundles.php`**
```php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\MessengerBundle\MessengerBundle::class => ['all' => true],
    Symfony\Bridge\Doctrine\Messenger\DoctrineMessengerBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
];
```

**`config/packages/doctrine.php`**
```php
return App::config([
    'doctrine' => [
        'dbal' => [
            'url' => env('DATABASE_URL'),
        ],
    ],
]);
```

**`config/packages/messenger.php`**
```php
namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'secret' => env('APP_SECRET'),
        'messenger' => [
            'transports' => [
                'images' => [
                    'dsn' => 'doctrine://default',
                    'options' => [
                        'table_name' => 'messenger_messages',
                        'queue_name' => 'image_processing',
                        'auto_setup' => true,
                        'redeliver_timeout' => 3600, // must exceed worst-case image job time
                    ],
                    'retry_strategy' => [
                        'max_retries' => 2,
                        'delay' => 1000,
                        'multiplier' => 2,
                        'max_delay' => 30000,
                    ],
                ],
            ],
            'routing' => [
                App\Messages\ProcessImageMessage::class => 'images',
            ],
        ],
    ],
]);
```

**`.env`**
```dotenv
APP_ENV=dev
APP_SECRET=gallinor_cli_secret
DATABASE_URL=sqlite:///%kernel.project_dir%/var/messenger/gallinor-queue.db
```

---

## Phase 2: Message & Handler

### 2.1 Message Class

**`src/Messages/ProcessImageMessage.php`**
```php
namespace App\Messages;

readonly class ProcessImageMessage
{
    public function __construct(
        public string $imagePath,
        public string $imageData,  // JSON-serialized ImageFile (path, size, isPortraitOrLivePhoto)
    ) {}
}
```

### 2.2 Message Handler

**`src/Images/MessageHandler/ProcessImageHandler.php`**
```php
namespace App\Images\MessageHandler;

use App\Images\Domain\CqLevelCalculator;
use App\Images\Domain\ImageFile;
use App\Images\Domain\ImageProcessingResult;
use App\Images\Domain\CalculationSkipReason;
use App\Messages\ProcessImageMessage;
use App\Shared\Infrastructure\WorkerHeartbeat;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\DBAL\Connection;

#[AsMessageHandler]
readonly class ProcessImageHandler
{
    public function __construct(
        private CqLevelCalculator $cqCalculator,
        private Connection $connection,
        private WorkerHeartbeat $heartbeat,
    ) {}

    public function __invoke(ProcessImageMessage $message): void
    {
        $workerId = $this->heartbeat->workerId();
        $this->heartbeat->beat($workerId);

        $image = ImageFile::fromJson($message->imageData);
        $startTime = microtime(true);
        $startedAt = time();

        try {
            $result = $this->cqCalculator->calculate(
                $image,
                heartbeat: fn () => $this->heartbeat->beat($workerId),
            );
            $processingTime = microtime(true) - $startTime;

            if ($result instanceof CalculationSkipReason) {
                $this->storeResult(
                    $message->imagePath,
                    'skipped',
                    qcTime: 0.0,
                    processingTime: $processingTime,
                    skipReason: $result->value,
                    createdAt: $startedAt,
                );
            } else {
                $this->storeResult(
                    $message->imagePath,
                    'completed',
                    cqLevel: $result->cqLevel,
                    qualityScore: $result->qualityScore,
                    avifSize: $result->avifSize,
                    originalSize: $result->originalSize,
                    qcTime: $result->qcTime,
                    processingTime: $processingTime,
                    createdAt: $startedAt,
                );
            }
        } catch (\Throwable $e) {
            $processingTime = microtime(true) - $startTime;
            $this->storeResult(
                $message->imagePath,
                'failed',
                errorMessage: $e->getMessage(),
                qcTime: 0.0,
                processingTime: $processingTime,
                createdAt: $startedAt,
            );
            throw;  // Re-throw for Messenger retry
        }
    }

    private function storeResult(
        string $imagePath,
        string $status,
        ?int $cqLevel = null,
        ?float $qualityScore = null,
        ?int $avifSize = null,
        ?int $originalSize = null,
        ?float $qcTime = null,
        ?float $processingTime = null,
        ?string $skipReason = null,
        ?string $errorMessage = null,
        ?int $createdAt = null,
    ): void {
        $sql = <<<'SQL'
            INSERT OR REPLACE INTO job_results
            (job_type, image_path, status, cq_level, quality_score, avif_size, original_size, qc_time, processing_time, skip_reason, error_message, created_at, completed_at)
            VALUES ('image', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL;
        
        $this->connection->executeStatement($sql, [
            $imagePath,
            $status,
            $cqLevel,
            $qualityScore,
            $avifSize,
            $originalSize,
            $qcTime,
            $processingTime,
            $skipReason,
            $errorMessage,
            $createdAt ?? time(),
            time(),
        ]);
    }
}
```

---

## Phase 3: Database Schema & Creation

### 3.1 Job Results Table

```sql
CREATE TABLE IF NOT EXISTS job_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_type TEXT NOT NULL,  -- 'image' | 'video' (future)
    image_path TEXT NOT NULL,
    status TEXT NOT NULL,  -- completed, skipped, failed
    cq_level INTEGER,
    quality_score REAL,
    avif_size INTEGER,
    original_size INTEGER,
    qc_time REAL,
    processing_time REAL,
    skip_reason TEXT,
    error_message TEXT,
    created_at INTEGER NOT NULL DEFAULT (CAST(strftime('%s','now') AS INTEGER)),
    completed_at INTEGER,
    UNIQUE(image_path, job_type)
);

CREATE INDEX IF NOT EXISTS idx_job_results_completed_at ON job_results(completed_at);
```

### 3.2 Worker Heartbeats
```sql
CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_id TEXT PRIMARY KEY,
    last_beat_at INTEGER NOT NULL
);
```
Workers call `WorkerHeartbeat::beat()` between external tool runs (avifenc/avifdec/ssimulacra2) so liveness reflects real progress.

### 3.3 Schema Version
```sql
CREATE TABLE IF NOT EXISTS schema_version (
    version INTEGER NOT NULL
);
-- If version mismatches, drop and recreate all tables.
```

### 3.4 Enable WAL Mode

On first DB connection:
```sql
PRAGMA journal_mode = WAL;
PRAGMA synchronous = NORMAL;
PRAGMA cache_size = -64000;  -- 64MB cache
PRAGMA temp_store = memory;
PRAGMA busy_timeout = 10000;  -- 10 seconds for concurrent access
```

### 3.5 Schema Creation Location
Create schema **in `images:squeeze`** (only when parallelism is enabled), before dispatching messages.  
This ensures the main process can query `job_results` without relying on worker startup order.

### 3.6 Schema Manager (Drop/Recreate on Version Mismatch)
Create `src/Shared/Infrastructure/JobSchemaManager.php`:
```php
namespace App\Shared\Infrastructure;

use Doctrine\DBAL\Connection;

final readonly class JobSchemaManager
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function ensureSchema(): void
    {
        DatabasePragmaConfigurator::apply($this->connection);

        // messenger_messages is created by Doctrine transport when auto_setup = true
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS job_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_type TEXT NOT NULL,
                image_path TEXT NOT NULL,
                status TEXT NOT NULL,
                cq_level INTEGER,
                quality_score REAL,
                avif_size INTEGER,
                original_size INTEGER,
                qc_time REAL,
                processing_time REAL,
                skip_reason TEXT,
                error_message TEXT,
                created_at INTEGER NOT NULL DEFAULT (CAST(strftime('%s','now') AS INTEGER)),
                completed_at INTEGER,
                UNIQUE(image_path, job_type)
            );
        SQL);

        $this->connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS idx_job_results_completed_at ON job_results(completed_at)'
        );

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS worker_heartbeats (
                worker_id TEXT PRIMARY KEY,
                last_beat_at INTEGER NOT NULL
            );
        SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_version (
                version INTEGER NOT NULL
            );
        SQL);
    }

    public function ensureVersion(int $expected): void
    {
        $version = $this->connection->fetchOne('SELECT version FROM schema_version LIMIT 1');
        if ($version === false) {
            $this->connection->executeStatement('INSERT INTO schema_version (version) VALUES (?)', [$expected]);
            return;
        }

        if ((int) $version !== $expected) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS job_results');
            $this->connection->executeStatement('DROP TABLE IF EXISTS worker_heartbeats');
            $this->connection->executeStatement('DROP TABLE IF EXISTS schema_version');
            $this->ensureSchema();
            $this->connection->executeStatement('INSERT INTO schema_version (version) VALUES (?)', [$expected]);
        }
    }
}
```

### 3.7 Worker Heartbeat Helper
Create `src/Shared/Infrastructure/WorkerHeartbeat.php`:
```php
namespace App\Shared\Infrastructure;

use Doctrine\DBAL\Connection;

final readonly class WorkerHeartbeat
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function workerId(): string
    {
        return gethostname() . ':' . getmypid();
    }

    public function beat(string $workerId): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO worker_heartbeats (worker_id, last_beat_at) VALUES (?, ?)',
            [$workerId, time()],
        );
    }
}
```

---

## Phase 4: ImageFile Serialization

### 4.1 Add Serialization Methods

Modify `src/Images/Domain/ImageFile.php`:
```php
public static function fromJson(string $json): self
{
    $data = json_decode($json, true);
    return new self(
        path: $data['path'],
        size: $data['size'],
        isPortraitOrLivePhoto: $data['isPortraitOrLivePhoto'] ?? false,
    );
}

public function jsonSerialize(): array
{
    return [
        'path' => $this->path,
        'size' => $this->size,
        'isPortraitOrLivePhoto' => $this->isPortraitOrLivePhoto,
    ];
}
```

### 4.2 Add Heartbeat Callback (Images)
Modify `CqLevelCalculator::calculate()` to accept an optional heartbeat callback and call it between external tool invocations:
```php
public function calculate(
    ImageFile $file,
    callable|null $statusCallback = null,
    callable|null $heartbeat = null,
): ImageProcessingResult|CalculationSkipReason {
    // ...
    for ($cqLevel = self::CQ_LEVEL_START; $cqLevel >= self::CQ_LEVEL_END; $cqLevel -= self::CQ_LEVEL_STEP) {
        if ($heartbeat !== null) {
            $heartbeat();
        }
        $this->imageTools->encodeToAvif($file->path, $tmpAvif, $cqLevel);

        if ($heartbeat !== null) {
            $heartbeat();
        }
        $this->imageTools->decodeAvifToPng($tmpAvif, $tmpPng);

        if ($heartbeat !== null) {
            $heartbeat();
        }
        $score = $this->imageTools->ssimulacra2Score($file->path, $tmpPng);
        // ...
    }
}
```
This keeps heartbeats fresh while long-running external tools are executing.

### 4.3 Extend ImageProcessingResult
Add a `processingTime` field and update `ImageBatchResult` totals accordingly:
```php
final readonly class ImageProcessingResult
{
    public function __construct(
        public int $avifSize,
        public int $originalSize,
        public int $cqLevel,
        public float $qualityScore,
        public float $qcTime,
        public float $processingTime,
    ) {}
}
```
Update `ImageBatchResult` to add `totalProcessingTime()` and include it in summaries.

---

## Phase 5: Modified Squeeze Command

### 5.1 Add Dependencies

```php
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\DBAL\Connection;
use App\Shared\Infrastructure\JobSchemaManager;

final class Squeeze extends Command
{
    private const int WORKER_STALE_SECONDS = 600; // 10 minutes
    private const int SCHEMA_VERSION = 1;

    public function __construct(
        private readonly CliHelper $cliHelper,
        private readonly ImageFileCollector $collector,
        private readonly Timing $timing,
        private readonly Platform $platform,
        private readonly RawArchiver $rawArchiver,
        private readonly MessageBusInterface $messageBus,
        private readonly Connection $connection,
        private readonly JobSchemaManager $schemaManager,
    ) {
        parent::__construct();
    }
```

### 5.2 Add Concurrency Override Flags and Dry-run Handling

```php
public function __invoke(
    OutputInterface $output,
    #[Option]
    bool $dryRun = false,
    #[Option(description: 'Override auto-detected concurrency (default: auto)')]
    int|null $concurrency = null,
    #[Option(description: 'Keep queue DB for debugging')]
    bool $keepQueue = false,
    #[Option(description: 'Enable Docker-only parallel mode (requires docker compose workers)')]
    bool $parallel = false,
    #[Argument]
    array $directories = [],
): int {
    // Dry-run: skip parallel processing, just report
    if ($dryRun) {
        return $this->dryRunOnly($output, $directories);
    }

    // Validate concurrency
    if ($concurrency !== null) {
        $maxSafe = $this->platform->nCores() * 2;
        if ($concurrency > $maxSafe) {
            $output->writeln(sprintf(
                '<comment>Warning: Concurrency %d > %d (2× cores) may cause thread contention</comment>',
                $concurrency, $maxSafe
            ));
        }
    }

    $calculatedConcurrency = $concurrency ?? $this->calculateConcurrency();
    $parallelEnabled = $parallel;

    if (! $parallelEnabled) {
        // Host mode: sequential only
        return $this->processSequentially($output, $directories);
    }

    // Docker mode: use $calculatedConcurrency as a recommended worker count
    $output->writeln(sprintf(
        '<comment>Docker mode: scale workers to %d (docker compose up --scale gallinor-worker=%d)</comment>',
        $calculatedConcurrency,
        $calculatedConcurrency,
    ));
```

**Collector change (DB is source of truth)**  
Add `AvifFilter::All` and use it in parallel mode so filesystem `.avif` presence does not override DB resume logic.

### 5.3 Pre-check Required Tools

```php
private function validateTools(): void
{
    $requiredTools = ['avifenc', 'avifdec', 'ssimulacra2', 'xz', 'tar'];
    foreach ($requiredTools as $tool) {
        try {
            $this->platform->findTool($tool);
        } catch (RuntimeException) {
            throw new RuntimeException("Required tool '$tool' not found. Please install it and try again.");
        }
    }
}
```

Call this before dispatching any messages - fail fast if tools missing.

### 5.4 Pre-dispatch Resume Check

```php
private function getAlreadyProcessed(array $jpegs): array
{
    $processed = [];
    $chunks = array_chunk($jpegs, 500);  // Note: theoretical max 999 SQLite params

    foreach ($chunks as $chunk) {
        $paths = array_map(static fn (ImageFile $image): string => $image->path, $chunk);
        $placeholders = implode(',', array_fill(0, count($paths), '?'));

        $sql = "SELECT image_path FROM job_results
                WHERE image_path IN ($placeholders)
                AND job_type = 'image'
                AND status = 'completed'";

        $results = $this->connection->fetchFirstColumn($sql, $paths);
        $processed = array_merge($processed, $results);
    }

    return array_flip($processed);  // Fast lookup: [path => true]
}
```

Usage in main flow:
```php
// DB is the source of truth for resume (do not skip based on .avif existence)
$processed = $this->getAlreadyProcessed($jpegs);
$toProcess = array_filter($jpegs, fn (ImageFile $j) => ! isset($processed[$j->path]));
```
Trade-off: if DB says "completed" but the `.avif` file is missing, the job is still skipped.

### 5.5 Process JPEGs in Parallel

Replace `processJpegs()` with:
Use `WORKER_STALE_SECONDS = 600` (10 minutes) for image workers.
```php
private function processJpegsInParallel(
    OutputInterface $output,
    array $jpegs,
    int|null $concurrency,
    bool $keepQueue,
): ImageBatchResult {
    // Apply PRAGMAs + ensure schema/version before any DB reads
    $this->schemaManager->ensureSchema();
    $this->schemaManager->ensureVersion(self::SCHEMA_VERSION);

    $calculatedConcurrency = $concurrency ?? $this->calculateConcurrency();

    $progressBar = $this->cliHelper->createProgressBar($output, count($jpegs), 'JPEGs');
    $progressBar->start();

    // Dispatch all messages
    foreach ($jpegs as $image) {
        $this->messageBus->dispatch(new ProcessImageMessage(
            $image->path,
            json_encode($image->jsonSerialize(), JSON_THROW_ON_ERROR),
        ));
    }

    // Workers are managed externally (Docker mode required)
    $result = new ImageBatchResult();
    $lastCompletedId = $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM job_results');
    $seenPaths = [];  // Track unique paths to avoid counting retries
    $completed = 0;

    try {
        while ($completed < count($jpegs)) {
            usleep(100_000); // 100ms fixed polling

            $newCompletions = $this->getCompletedResults($lastCompletedId);

            foreach ($newCompletions as $job) {
                $path = $job['image_path'];

                // Skip if we've already seen this path (from retry)
                if (isset($seenPaths[$path])) {
                    continue;
                }

                $seenPaths[$path] = true;
                $lastCompletedId = max($lastCompletedId, $job['id']);
                $completed++;
                $progressBar->setProgress($completed);

                $fileName = basename($job['image_path']);

                switch ($job['status']) {
                    case 'completed':
                        $this->writeCompletion($output, $progressBar, $fileName, $job);
                        $result->processed[$job['image_path']] = new ImageProcessingResult(
                            avifSize: $job['avif_size'],
                            originalSize: $job['original_size'],
                            cqLevel: $job['cq_level'],
                            qualityScore: $job['quality_score'],
                            qcTime: $job['qc_time'],
                            processingTime: $job['processing_time'],
                        );
                        break;

                    case 'skipped':
                        $this->writeSkip($output, $progressBar, $fileName, $job['skip_reason']);
                        $result->skipped[$job['image_path']] = CalculationSkipReason::from($job['skip_reason']);
                        break;

                    case 'failed':
                        $this->writeError($output, $progressBar, $fileName, $job['error_message']);
                        $result->errored[$job['image_path']] = $job['error_message'];
                        break;
                }
            }

            $pending = $this->getPendingCount();
            if ($this->getLiveWorkerCount(self::WORKER_STALE_SECONDS) === 0 && $pending > 0) {
                throw new RuntimeException('No live workers detected while jobs remain in the queue.');
            }
        }
    } finally {
        // No internal workers to stop (Docker-managed)
    }

    $progressBar->finish();

    // Cleanup after workers stop (skip if --keep-queue)
    if (! $keepQueue) {
        $this->cleanupBatch($output);
    }

    return $result;
}

private function cleanupBatch(OutputInterface $output): void
{
    // Delete results older than 24 hours
    $this->connection->executeStatement(
        'DELETE FROM job_results WHERE completed_at < ?',
        [time() - 86400]
    );

    // Checkpoint WAL to truncate; VACUUM only after workers are stopped
    $this->connection->executeStatement('PRAGMA wal_checkpoint(TRUNCATE)');
    try {
        $this->connection->executeStatement('VACUUM');
    } catch (\Throwable) {
        $output->writeln('<comment>VACUUM skipped: database locked</comment>');
    }
}

private function calculateConcurrency(): int
{
    $nCores = $this->platform->nCores();
    return max(1, min((int)($nCores / 4), (int)($nCores / 8) + 2));
}

private function getCompletedResults(int $lastId): array
{
    $sql = <<<'SQL'
        SELECT * FROM job_results
        WHERE id > ?
        ORDER BY id ASC
        LIMIT 20
    SQL;

    return $this->connection->fetchAllAssociative($sql, [$lastId]);
}

private function getPendingCount(): int
{
    return (int) $this->connection->fetchOne(
        "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'image_processing'"
    );
}

private function getLiveWorkerCount(int $staleSeconds): int
{
    $since = time() - $staleSeconds;
    return (int) $this->connection->fetchOne(
        'SELECT COUNT(*) FROM worker_heartbeats WHERE last_beat_at >= ?',
        [$since],
    );
}

private function writeCompletion(OutputInterface $output, ProgressBar $progressBar, string $fileName, array $job): void
{
    $progressBar->clear();
    $saved = $job['original_size'] - $job['avif_size'];
    $output->writeln(sprintf(
        '<info>✓ %s</info> | cq=%d, score=%.1f, saved %s (qc %.1fs / total %.1fs)',
        $fileName,
        $job['cq_level'],
        $job['quality_score'],
        $this->cliHelper->formatBytes($saved),
        $job['qc_time'],
        $job['processing_time'],
    ));
    $progressBar->display();
}

private function writeSkip(OutputInterface $output, ProgressBar $progressBar, string $fileName, string $reason): void
{
    $progressBar->clear();
    $output->writeln(sprintf(
        '<comment>Skipped: %s (%s)</comment>',
        $fileName,
        $reason
    ));
    $progressBar->display();
}

private function writeError(OutputInterface $output, ProgressBar $progressBar, string $fileName, string $error): void
{
    $progressBar->clear();
    $output->writeln(sprintf(
        '<error>%s: %s</error>',
        $fileName,
        $error
    ));
    $progressBar->display();
}
```

`--keepalive` is supported by Doctrine transport and keeps `delivered_at` fresh during long jobs.

Extend `ImageProcessingResult` to include `processingTime` and update `ImageBatchResult` to sum it for totals.

Update output summaries to display both totals (e.g., in `JPEG Summary` or `Timing`):  
`QC time total` (sum of `qcTime`) and `Processing time total` (sum of `processingTime`).

### 5.6 Error Handling Strategies

**Missing tools**: Pre-check in main process, fail fast before dispatching.

**Disk full**: Stop workers immediately, report error to user (no retry).

**Corrupted JPEG**: Mark as failed, continue with other images (Messenger will retry 2 times).

**DB locked errors**: SQLite busy_timeout = 10s; `wal_checkpoint(TRUNCATE)` + `VACUUM` only after workers stop, skip if locked.

**Worker crash**:
- Docker mode: if no live heartbeats and queue still has jobs, fail fast.
- `redeliver_timeout` must be set above worst-case job time; use `--keepalive` with Doctrine transport.

**Zero images**: Exit early without starting workers.

**Video note (future)**: ffmpeg encoding emits `-progress` output; use the existing `lineCallback` to call `WorkerHeartbeat::beat()` during encoding. VMAF scoring provides no progress, so set a larger stale window (e.g., `max(30 min, duration * 4)`) to avoid false dead-worker detection during VMAF.

---

## Phase 6: Cleanup Command

### 6.1 Dedicated Cleanup Command

**`src/Messenger/Ui/Cli/Cleanup.php`**
```php
namespace App\Messenger\Ui\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\DBAL\Connection;
use App\Shared\Infrastructure\DatabasePragmaConfigurator;

#[AsCommand(name: 'messenger:cleanup', description: 'Clean up old job results')]
final class Cleanup extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_OPTIONAL,
            'Delete results older than N hours (default: 24)',
            24,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        DatabasePragmaConfigurator::apply($this->connection);
        $olderThan = (int) $input->getOption('older-than');
        
        $sql = 'DELETE FROM job_results WHERE completed_at < ?';
        $deleted = $this->connection->executeStatement($sql, [time() - ($olderThan * 3600)]);
        
        $this->connection->executeStatement('PRAGMA wal_checkpoint(TRUNCATE)');
        try {
            $this->connection->executeStatement('VACUUM');
        } catch (\Throwable) {
            $output->writeln('<comment>VACUUM skipped: database locked</comment>');
        }

        $output->writeln(sprintf('<info>Cleaned up %d old job records (older than %d hours)</info>', $deleted, $olderThan));

        return Command::SUCCESS;
    }
}
```

### 6.2 Wire Cleanup Command in app.php

With FrameworkBundle + auto-discovery, `Cleanup` is registered automatically; no manual wiring required.

---

## Phase 7: Docker Compose Configuration

Create Docker Compose setup with dynamic replica calculation and flexible gallery path support (CLI arg, ENV var, or default).

### 7.1 Dockerfile & Compose

**`Dockerfile`**
- Base: `php:8.5-cli`.
- Install system dependencies: `ffmpeg`, `libavif-bin` (or build `libavif`), `xz-utils`, `git`, `unzip`.
- Install Rust & `ssimulacra2_rs` (or `ssimulacra2` if you ship the C++ binary); verify the Linux binary name and hard-code it in `NativePlatform`.
- `NativePlatform` exception for non-macOS/Windows is removed in Phase 1.

**`docker-compose.yml`**
```yaml
version: '3.8'

services:
  gallinor-app:
    build: .
    command: php app.php images:squeeze ${GALLINOR_GALLERY_PATH:-/data/gallery} --parallel
    environment:
      - DATABASE_URL=sqlite:///%kernel.project_dir%/var/messenger/gallinor-queue.db
    volumes:
      - ${GALLINOR_GALLERY_PATH:-./gallery}:/data/gallery
      - gallinor-messenger:/app/var/messenger
    depends_on:
      - gallinor-worker

  gallinor-worker:
    build: .
    command: php app.php messenger:consume images --memory-limit=256M --keepalive=5
    environment:
      - DATABASE_URL=sqlite:///%kernel.project_dir%/var/messenger/gallinor-queue.db
    volumes:
      - ${GALLINOR_GALLERY_PATH:-./gallery}:/data/gallery
      - gallinor-messenger:/app/var/messenger
    restart: on-failure:3
    # Compose limitation: no restart delay; for backoff, rely on Docker's default restart behavior.

  gallinor-cleanup:
    build: .
    command: php app.php messenger:cleanup --older-than=24
    environment:
      - DATABASE_URL=sqlite:///%kernel.project_dir%/var/messenger/gallinor-queue.db
    volumes:
      - gallinor-messenger:/app/var/messenger
    # Optional: Run on schedule via cron or manual execution

volumes:
  gallinor-messenger:
```

**Implementation Idea for Gallery Path:**
- Docker Compose's `${VAR:-default}` syntax provides environment variable interpolation with defaults
- Volume mounts and command arguments both support interpolation, ensuring consistent paths
- Deploy scripts follow a simple priority resolution: CLI argument first, then ENV var, finally default
- This hybrid approach allows users to invoke with `./deploy.sh /path`, `GALLERY_PATH=/path ./deploy.sh`, or `./deploy.sh` for defaults
- SQLite queue is stored in a **named volume** by default to avoid macOS/Windows bind-mount slowness; switch to a bind mount only for debugging

### 7.2 Deploy Scripts (Hybrid Gallery Path Support)

Both deploy scripts support three gallery path invocation styles with priority: CLI argument → ENV var → default.

**`scripts/deploy.sh`** (macOS/Linux)
```bash
#!/usr/bin/env bash

# Resolve gallery path (priority: CLI arg > ENV var > default)
if [[ -n "$1" ]]; then
    GALLERY_PATH="$1"
elif [[ -n "$GALLINOR_GALLERY_PATH" ]]; then
    GALLERY_PATH="$GALLINOR_GALLERY_PATH"
else
    GALLERY_PATH="./gallery"
fi
export GALLINOR_GALLERY_PATH="$GALLERY_PATH"

# Calculate optimal replicas based on CPU cores
if [[ "$OSTYPE" == "darwin"* ]]; then
    N_CORES=$(sysctl -n hw.ncpu)
elif [[ "$OSTYPE" == "msys" ]] || [[ "$OSTYPE" == "win32" ]]; then
    N_CORES=$NUMBER_OF_PROCESSORS
else
    N_CORES=$(nproc)
fi

# Same formula as CLI: max(1, min(nCores / 4, floor(nCores / 8) + 2))
REPLICAS=$(python3 -c "print(max(1, min(int($N_CORES / 4), int($N_CORES / 8) + 2)))")

export GALLINOR_WORKER_REPLICAS=$REPLICAS
echo "Starting with $REPLICAS workers for $N_CORES cores, processing $GALLERY_PATH"

# Start containers
docker compose up -d --scale gallinor-worker=$REPLICAS
```

**`scripts/deploy.bat`** (Windows)
```bat
@echo off

REM Resolve gallery path (priority: CLI arg > ENV var > default)
if "%~1"=="" (
    if "%GALLINOR_GALLERY_PATH%"=="" (
        set GALLERY_PATH=.\gallery
    ) else (
        set GALLERY_PATH=%GALLINOR_GALLERY_PATH%
    )
) else (
    set GALLERY_PATH=%~1
)
set GALLINOR_GALLERY_PATH=%GALLERY_PATH%

REM Calculate optimal replicas (Windows PowerShell)
for /f "delims=" %%i in ('powershell -Command "[Environment]::ProcessorCount"') do set N_CORES=%%i
for /f "delims=" %%i in ('powershell -Command "[Math]::Max(1, [Math]::Min([int]([Environment]::ProcessorCount / 4), [int]([Environment]::ProcessorCount / 8) + 2))"') do set REPLICAS=%%i

set GALLINOR_WORKER_REPLICAS=%REPLICAS%
echo Starting with %REPLICAS% workers for %N_CORES% cores, processing %GALLERY_PATH%

REM Start containers
docker compose up -d --scale gallinor-worker=%REPLICAS%
```

### 7.3 Deployment Commands

Three invocation styles supported (all work identically):

**Style 1: Command-line argument** (explicit)
```bash
# macOS/Linux
./scripts/deploy.sh ~/Photos/vacation-2024

# Windows
scripts\deploy.bat C:\Users\User\Pictures\vacation-2024
```

**Style 2: Environment variable** (flexible, works with .env files)
```bash
# macOS/Linux
GALLINOR_GALLERY_PATH=~/Photos/vacation-2024 ./scripts/deploy.sh

# Windows
set GALLINOR_GALLERY_PATH=C:\Users\User\Pictures\vacation-2024
scripts\deploy.bat
```

**Style 3: Default** (simplest, uses ./gallery or .\gallery)
```bash
# macOS/Linux
./scripts/deploy.sh

# Windows
scripts\deploy.bat
```

**Cleanup old results manually:**
```bash
docker compose run gallinor-cleanup
```

**Manual scaling (without scripts):**
```bash
docker compose up -d --scale gallinor-worker=3
```

---

## Phase 8: App Entrypoint Refactoring

### 8.1 Create Kernel

Create `src/Kernel.php` to handle bundle loading and configuration (Standard Symfony Pattern):
```php
namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
```

### 8.2 Update app.php

Refactor `app.php` to boot the Kernel and use the FrameworkBundle Application for automatic command discovery.

```php
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

// Load .env variables
(new Dotenv())->bootEnv(__DIR__ . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$application = new Application($kernel);
$application->run();
```

**Manual DI removed**: All commands auto-discovered via container, no manual wiring needed in app.php.
**Note**: `config/bundles.php` must exist for FrameworkBundle/Messenger/Doctrine bundles to load.

---

## Testing Strategy

### Unit Tests
- `ProcessImageHandlerTest` - Test handler logic with mock CqLevelCalculator
- `CleanupCommandTest` - Test cleanup command
- `JobSchemaManagerTest` - Test schema creation + version reset
- `WorkerHeartbeatTest` - Test heartbeat insert/update logic

### Integration Tests
- Use real `CqLevelCalculator` with small test JPEGs in vfsStream
- Test parallel execution with 2-3 workers using file-based SQLite (shared DB across processes)
- Verify result collection, progress updates, and resume functionality
- Test worker crash scenarios and retry logic

---

## Performance Expectations

### M1 Pro (8P+2E cores)
- **Concurrent JPEGs**: 2
- **Expected gain**: 30-50% faster than sequential

### Ryzen 7900X (12C/24T)
- **Concurrent JPEGs**: 3
- **Expected gain**: 80-100% faster (may match or exceed M1 Pro)

---

## Implementation Order

1. **Docker Compose + Image** - Dockerfile, compose file, deploy scripts; verify toolchain inside image and determine Linux `ssimulacra2` binary name
2. **Platform** - Enable Linux in `NativePlatform`, hard-code Linux `ssimulacra2` mapping based on Docker check
3. **Dependencies** - Install framework, messenger, doctrine-bundle, doctrine-messenger, dbal, config, dotenv
4. **Framework Setup** - Create Kernel, `config/bundles.php`, `config/services.php`, `config/packages/doctrine.php`, `config/packages/messenger.php`, `.env`, refactor app.php
5. **Database** - `DatabasePragmaConfigurator`, `JobSchemaManager`, schema version/heartbeat tables
6. **Message & Handler** - ProcessImageMessage, ProcessImageHandler, WorkerHeartbeat, heartbeat callback in CqLevelCalculator
7. **Serialization & Filtering** - Add ImageFile JSON, add `AvifFilter::All` and adjust collector
8. **Command Changes** - Modify Squeeze (parallel enablement, resume, liveness, cleanup), add cleanup command
9. **Testing** - Unit tests (schema manager, heartbeat, handler, cleanup), integration tests (file-based SQLite)
10. **Final Review** - Test host and Docker modes end-to-end

---

## Next Steps

1. Implement Phase 1-5 (core functionality)
2. Test host (sequential) mode locally
3. Implement Phase 6-7 (Docker deployment)
4. Add tests
5. Update documentation
6. Performance benchmarking on M1 Pro vs Ryzen 7900X
