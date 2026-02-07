# Parallel Image Processing Implementation Plan

## Summary

Add parallel JPEG processing to `images:squeeze` using Symfony Messenger with
SQLite (WAL) and DB-backed resume.
**Key Architecture Updates:**

- **Framework**: Add Symfony `Kernel` + `FrameworkBundle` (configuration via
  `App::config`).
- **Platform**: Enable Linux in `NativePlatform` for Docker images.
- **Database**: Use DoctrineBundle + Doctrine Messenger transport; schema is
  created by `images:squeeze` when parallelism is enabled.
- **Deployment**: Parallel execution is **Docker-only**; host mode remains
  sequential. Use named volume for SQLite to avoid bind-mount slowness.
- **Worker Scaling**: Deploy scripts run `nproc` inside container to detect CPU
  limits (respects Docker cgroup v2).
- **Entrypoint**: `app.php` refactored to boot the Kernel and load config via
  Symfony.
- **Performance**: Workers process multiple media files concurrently (JPEGs +
  ARWs).
- **Resume**: DB is the source of truth for completed jobs (even if the
  optimized file is missing — default `.heic`).
- **Liveness**: Worker heartbeats stored in DB; main process detects dead
  workers without per-file timeouts.
- **ARW Archival**: `ProcessArwMessage` with directory locking (flock) to
  prevent race conditions.
- **Pre-flight Check**: Verify live workers before dispatching messages; error
  if none running.

## Initial Requirements

- **Dependencies**: `symfony/framework-bundle`, `symfony/messenger`,
  `symfony/doctrine-messenger`, `doctrine/doctrine-bundle`, `doctrine/dbal`,
  `symfony/config`, `symfony/dotenv`
- **Database**: SQLite with WAL mode at `var/messenger/gallinor-queue.db` (named
  Docker volume in production)
- **Concurrency**: Auto-detected based on CPU cores (`max(1, min(nCores/4,
  floor(nCores/8) + 2)`), overrideable via `--concurrency=N`
- **Progress tracking**: Poll `job_results` every 100ms for completions, update
  progress bar and display results
- **Cleanup**: Optional per-run cleanup (skip with `--keep-queue`);
  `messenger:cleanup` command; use `wal_checkpoint(TRUNCATE)` and attempt
  `VACUUM` only after workers stop
- **Retry strategy**: 2 retries with exponential backoff (1s, 2s, max 30s delay)
  and `redeliver_timeout` set above worst-case job time
- **Worker management**: Docker Compose handles worker replicas; no in-app
  subprocess management
- **Liveness**: Worker heartbeats stored in DB; main process exits if no live
  workers and queue still has jobs
- **Resume semantics**: DB is the source of truth (completed DB entry skips even
  if the optimized file is missing — default `.heic`)
- **Platform support**: Deploy scripts for macOS/Linux (`.sh`) and Windows
  (`.bat`) using native tools, no PHP required
- **DB versioning**: Schema version checked on startup; mismatch =>
  drop/recreate tables (resume DB is disposable)
- **Gallery path flexibility**: Deploy scripts support three invocation styles:
  - Command-line argument: `./deploy.sh /path/to/gallery`
  - Environment variable: `GALLINOR_GALLERY_PATH=/path/to/gallery ./deploy.sh`
  - Default (no argument): Uses `./gallery` (Linux/Mac) or `.\gallery` (Windows)
  - **Hybrid approach**: Priority order: CLI arg → ENV var → default
- **Testing**: Unit tests for queue/handler; integration tests use file-based
  SQLite (not `:memory:`) for multi-process support

## Overview

Implement Symfony Messenger-based parallel processing for `images:squeeze` with
SQLite queue:

- **Host mode**: sequential only (no queue).
- **Docker mode**: parallel (queue + workers managed by Compose).

---

## Architecture

### Data Flow

```text
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
- Determine the Linux `ssimulacra2` binary name inside the Docker image and
  hard-code mapping (expected: `ssimulacra2` or `ssimulacra2_rs`).

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

- `JobSchemaManager::ensureSchema()` before any queue reads/writes in parallel
  runs.
- `messenger:cleanup` before maintenance operations (`wal_checkpoint`,
  `VACUUM`).

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
                        'redeliver_timeout' => 600, // 10 minutes - detect crashed workers during long tool runs
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

## Phase 2: Messages & Handlers

### 2.1 Image Processing Message Class

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

### 2.3 ARW Archival Message Class

**`src/Messages/ProcessArwMessage.php`**

```php
namespace App\Messages;

readonly class ProcessArwMessage
{
    public function __construct(
        public string $directory,
        public array $arwFiles,  // JSON-serialized array of ARW file paths
    ) {}
}
```

### 2.4 ARW Archival Message Handler

**`src/Images/MessageHandler/ProcessArwHandler.php`**

```php
namespace App\Images\MessageHandler;

use App\Messages\ProcessArwMessage;
use App\Images\Domain\RawArchiver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\DBAL\Connection;
use App\Shared\Infrastructure\WorkerHeartbeat;

#[AsMessageHandler]
readonly class ProcessArwHandler
{
    public function __construct(
        private RawArchiver $archiver,
        private Connection $connection,
        private WorkerHeartbeat $heartbeat,
    ) {}

    public function __invoke(ProcessArwMessage $message): void
    {
        $workerId = $this->heartbeat->workerId();
        $this->heartbeat->beat($workerId);

        $arwFiles = json_decode($message->arwFiles, true);

        // Directory lock to prevent concurrent archival
        $lockFile = $message->directory . '/.gallinor.lock';
        $fh = fopen($lockFile, 'w');
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Could not acquire lock for {$message->directory}");
        }

        try {
            $archiveSize = $this->archiver->archive($message->directory, $arwFiles);
            // Store result in DB for progress tracking
            $this->storeArwResult($message->directory, count($arwFiles), $archiveSize);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function storeArwResult(string $directory, int $fileCount, int $archiveSize): void
    {
        $sql = <<<'SQL'
            INSERT OR REPLACE INTO job_results
            (job_type, image_path, status, cq_level, quality_score, avif_size, original_size, qc_time, processing_time, skip_reason, error_message, created_at, completed_at)
            VALUES ('arw', ?, 'completed', NULL, NULL, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)
        SQL;

        $this->connection->executeStatement($sql, [
            $directory,
            $archiveSize,
            time(),
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

Workers call `WorkerHeartbeat::beat()` between external tool runs
(avifenc/avifdec/ssimulacra2) so liveness reflects real progress.

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

Create schema **in `images:squeeze`** (only when parallelism is enabled), before
dispatching messages.
This ensures the main process can query `job_results` without relying on worker
startup order.

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

Modify `CqLevelCalculator::calculate()` to accept an optional heartbeat callback
and call it between external tool invocations:

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

Update `ImageBatchResult` to add `totalProcessingTime()` and include it in
summaries.

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

### 5.1 Add ARW Archival Message

Add `ProcessArwMessage` and `ProcessArwHandler` for parallel ARW archival.
Similar structure to image processing:

- Message contains directory path and list of ARW files
- Handler calls `RawArchiver::archive()` with directory lock (flock) to prevent
  race conditions
- Lock file: `.gallinor.lock` in target directory, using `LOCK_EX` with
  `LOCK_NB` for fail-fast

**`src/Messages/ProcessArwMessage.php`**

```php
namespace App\Messages;

readonly class ProcessArwMessage
{
    public function __construct(
        public string $directory,
        public array $arwFiles,  // JSON-serialized array of ARW file paths
    ) {}
}
```

**`src/Images/MessageHandler/ProcessArwHandler.php`**

```php
namespace App\Images\MessageHandler;

use App\Messages\ProcessArwMessage;
use App\Images\Domain\RawArchiver;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\DBAL\Connection;
use App\Shared\Infrastructure\WorkerHeartbeat;

#[AsMessageHandler]
readonly class ProcessArwHandler
{
    public function __construct(
        private RawArchiver $archiver,
        private Connection $connection,
        private WorkerHeartbeat $heartbeat,
    ) {}

    public function __invoke(ProcessArwMessage $message): void
    {
        $workerId = $this->heartbeat->workerId();
        $this->heartbeat->beat($workerId);

        $arwFiles = json_decode($message->arwFiles, true);

        // Directory lock to prevent concurrent archival
        $lockFile = $message->directory . '/.gallinor.lock';
        $fh = fopen($lockFile, 'w');
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Could not acquire lock for {$message->directory}");
        }

        try {
            $archiveSize = $this->archiver->archive($message->directory, $arwFiles);
            // Store result in DB for progress tracking
            $this->storeArwResult($message->directory, count($arwFiles), $archiveSize);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function storeArwResult(string $directory, int $fileCount, int $archiveSize): void
    {
        $sql = <<<'SQL'
            INSERT OR REPLACE INTO job_results
            (job_type, image_path, status, cq_level, quality_score, avif_size, original_size, qc_time, processing_time, skip_reason, error_message, created_at, completed_at)
            VALUES ('arw', ?, 'completed', NULL, NULL, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)
        SQL;

        $this->connection->executeStatement($sql, [
            $directory,
            $archiveSize,
            time(),
            time(),
        ]);
    }
}
```

Update `config/packages/messenger.php` to add ARW routing:

```php
'routing' => [
    App\Messages\ProcessImageMessage::class => 'images',
    App\Messages\ProcessArwMessage::class => 'images',  // Same queue
],
```

---

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
Add `AvifFilter::All` and use it in parallel mode so filesystem `.avif` presence
does not override DB resume logic.

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
// DB is the source of truth for resume (do not skip based on optimized file existence)
$processed = $this->getAlreadyProcessed($jpegs);
$toProcess = array_filter($jpegs, fn (ImageFile $j) => ! isset($processed[$j->path]));
```

Trade-off: if DB says "completed" but the optimized file (default `.heic`) is
missing, the job is still skipped.

### 5.5 Process JPEGs in Parallel

Replace `processJpegs()` with:
Use `WORKER_STALE_SECONDS = 60` (1 minute) for image workers (tool-aware: images
have shorter runs than videos).

**Pre-flight worker check** (before dispatching):

```php
// Check for live workers before dispatching messages
if (count($jpegs) > 0 && $this->getLiveWorkerCount(60) === 0) {
    throw new RuntimeException(
        'No live workers detected. Use deploy scripts (./scripts/deploy.sh) or start Docker workers first: docker compose up -d --scale gallinor-worker=N'
    );
}
```

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

    // Note: Progress bar may stall momentarily during retries because:
    // - Failed jobs create new DB rows (same path, different ID)
    // - We filter duplicates via $seenPaths
    // - LIMIT 20 query returns fewer than 20 actual completions after filtering

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
                            format: ImageFormat::Heic,
                            optimizedSize: $job['heic_size'],
                            originalSize: $job['original_size'],
                            qualityValue: $job['q'],
                            qualityLabel: 'q',
                            qualityScore: $job['quality_score'],
                            qcTime: $job['qc_time'],
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

/** @param array<string, array<string>> $arwsByDir */
private function archiveArwsInParallel(
    OutputInterface $output,
    array $arwsByDir,
    bool $keepQueue,
): array {
    if (empty($arwsByDir)) {
        return ['archived' => 0, 'sizeBefore' => 0, 'sizeAfter' => 0];
    }

    $progressBar = $this->cliHelper->createProgressBar($output, count($arwsByDir), 'ARW dirs');
    $progressBar->start();

    $archived = 0;
    $sizeBefore = 0;
    $sizeAfter = 0;
    $lastCompletedId = $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM job_results');
    $seenDirs = [];  // Track unique directories to avoid counting retries
    $completed = 0;

    // Calculate size before
    foreach ($arwsByDir as $arwFiles) {
        foreach ($arwFiles as $arwFile) {
            $sizeBefore += filesize($arwFile);
        }
    }

    // Dispatch all messages
    foreach ($arwsByDir as $dir => $arwFiles) {
        $this->messageBus->dispatch(new ProcessArwMessage(
            $dir,
            json_encode($arwFiles, JSON_THROW_ON_ERROR),
        ));
    }

    try {
        while ($completed < count($arwsByDir)) {
            usleep(100_000); // 100ms fixed polling

            $newCompletions = $this->getCompletedArwResults($lastCompletedId);

            foreach ($newCompletions as $job) {
                $dir = $job['image_path'];  // directory path stored in image_path column

                if (isset($seenDirs[$dir])) {
                    continue;
                }

                $seenDirs[$dir] = $job['avif_size'];  // Store archive size
                $lastCompletedId = max($lastCompletedId, $job['id']);
                $completed++;
                $progressBar->setProgress($completed);

                $dirName = basename($dir);
                $progressBar->setMessage(sprintf('%s | %s', $dirName, $this->cliHelper->formatBytes($job['avif_size'])), 'status');
            }

            $pending = $this->getPendingCount();
            if ($this->getLiveWorkerCount(60) === 0 && $pending > 0) {
                throw new RuntimeException('No live workers detected while jobs remain in the queue.');
            }
        }
    } finally {
        // No internal workers to stop (Docker-managed)
    }

    $progressBar->finish();
    $output->writeln('');

    // Sum final archive sizes
    $sizeAfter = array_sum($seenDirs);

    return ['archived' => $archived, 'sizeBefore' => $sizeBefore, 'sizeAfter' => $sizeAfter];
}

private function getCompletedArwResults(int $lastId): array
{
    $sql = <<<'SQL'
        SELECT * FROM job_results
        WHERE id > ? AND job_type = 'arw'
        ORDER BY id ASC
        LIMIT 20
    SQL;

    return $this->connection->fetchAllAssociative($sql, [$lastId]);
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

`--keepalive` is supported by Doctrine transport and keeps `delivered_at` fresh
during long jobs.

Extend `ImageProcessingResult` to include `processingTime` and update
`ImageBatchResult` to sum it for totals.

Update output summaries to display both totals (e.g., in `JPEG Summary` or
`Timing`):
`QC time total` (sum of `qcTime`) and `Processing time total` (sum of
`processingTime`).

### 5.6 Error Handling Strategies

**Missing tools**: Pre-check in main process, fail fast before dispatching.

**Disk full**: Stop workers immediately, report error to user (no retry).

**Corrupted JPEG**: Mark as failed, continue with other images (Messenger will
retry 2 times).

**DB locked errors**: SQLite busy_timeout = 10s; `wal_checkpoint(TRUNCATE)` +
`VACUUM` only after workers stop, skip if locked.

**Worker crash**:

- Docker mode: if no live heartbeats and queue still has jobs, fail fast.
- `redeliver_timeout` must be set above worst-case job time; use `--keepalive`
  with Doctrine transport.

**Zero images**: Exit early without starting workers.

**Video note (future)**: ffmpeg encoding emits `-progress` output; use the
existing `lineCallback` to call `WorkerHeartbeat::beat()` during encoding. VMAF
scoring provides no progress, so set a larger stale window (e.g., `max(30 min,
duration * 4)`) to avoid false dead-worker detection during VMAF.

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

With FrameworkBundle + auto-discovery, `Cleanup` is registered automatically; no
manual wiring required.

---

## Phase 7: Docker Compose Configuration

Create Docker Compose setup with dynamic replica calculation and flexible
gallery path support (CLI arg, ENV var, or default).

### 7.1 Dockerfile & Compose

**`Dockerfile`**

- Base: `php:8.5-cli`.
- Install system dependencies:
  - HEIC: `libheif-examples` (for `heif-enc` / `heif-convert`)
  - Optional AVIF (only if `--format=avif`): `libavif-bin` (or build `libavif`)
  - Quality + metadata: `ffmpeg`, `libimage-exiftool-perl`, `xz-utils`, `git`, `unzip`.
- `ffmpeg`:
  - Minimum: `libx265` support for CPU HEVC fallback.
  - Recommended: build a deterministic `ffmpeg` binary that includes both
    `libx265` and NVIDIA NVENC (`hevc_nvenc`) so the same image can run CPU-only
    everywhere and use NVENC when the host provides an NVIDIA GPU.
- Install Rust & `ssimulacra2_rs` (or `ssimulacra2` if you ship the C++ binary);
  verify the Linux binary name and hard-code it in `NativePlatform`.
- `NativePlatform` exception for non-macOS/Windows is removed in Phase 1.

#### Recommended Dockerfile snippet (NVENC-capable FFmpeg + CPU fallback)

Relying on distro `ffmpeg` packages is inconsistent for NVENC: some builds ship
`hevc_nvenc`, others do not. To avoid surprises, build `ffmpeg` from source in a
multi-stage Dockerfile with `nv-codec-headers` and `libx265`.

High-level outline:

- Build stage: install toolchain + `nv-codec-headers` + `libx265-dev`, then
  compile `ffmpeg` with NVENC + `libx265`.
- Runtime stage: copy the built `ffmpeg` into the final image.
- NVENC runtime libraries come from the host driver (via NVIDIA Container
  Toolkit / Docker Desktop GPU integration), not from the image.

Example snippet (trim as needed):

```dockerfile
FROM php:8.5-cli AS ffmpeg-build

RUN apt-get update && apt-get install -y --no-install-recommends \
  ca-certificates git build-essential pkg-config yasm nasm \
  libx265-dev \
  && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://github.com/FFmpeg/nv-codec-headers.git /tmp/nv && \
  make -C /tmp/nv install

RUN git clone --depth 1 https://github.com/FFmpeg/FFmpeg.git /tmp/ffmpeg && \
  cd /tmp/ffmpeg && \
  ./configure --prefix=/opt/ffmpeg --disable-doc --disable-debug \
    --enable-gpl --enable-libx265 \
    --enable-nvenc --enable-nvdec --enable-cuvid && \
  make -j"$(nproc)" && make install

FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
  libheif-examples \
  # optional AVIF support
  libavif-bin \
  ffmpeg libimage-exiftool-perl xz-utils \
  # libx265 runtime package name can vary by base image; libx265-dev is the simplest option here.
  libx265-dev \
  && rm -rf /var/lib/apt/lists/*
COPY --from=ffmpeg-build /opt/ffmpeg/ /usr/local/
```

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

**`docker-compose.nvidia.yml`** (optional; NVIDIA hosts only)

Keep NVIDIA GPU configuration in a separate override file so the base Compose
setup works on machines without an NVIDIA runtime/driver (e.g., macOS).

```yaml
services:
  gallinor-app:
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]

  gallinor-worker:
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

**Implementation Idea for Gallery Path:**

- Docker Compose's `${VAR:-default}` syntax provides environment variable
  interpolation with defaults
- Volume mounts and command arguments both support interpolation, ensuring
  consistent paths
- Deploy scripts follow a simple priority resolution: CLI argument first, then
  ENV var, finally default
- This hybrid approach allows users to invoke with `./deploy.sh /path`,
  `GALLERY_PATH=/path ./deploy.sh`, or `./deploy.sh` for defaults
- SQLite queue is stored in a **named volume** by default to avoid macOS/Windows
  bind-mount slowness; switch to a bind mount only for debugging

### 7.2 Hardware Acceleration (GPU) Notes

This project’s primary GPU acceleration target is **video encoding**
(`videos:squeeze` via `hevc_nvenc` on NVIDIA). Image processing may gain GPU
support later (TBD).

#### macOS quirk: no Apple VideoToolbox inside Docker

`videotoolbox` (Apple’s hardware encode/decode) is a macOS framework. Docker on
macOS runs **Linux containers inside a Linux VM**, so `ffmpeg` inside the
container cannot access `videotoolbox`.

Implications:

- Running Gallinor in Docker on macOS will use **CPU encoding** (e.g.,
  `libx265`), not VideoToolbox.
- If you want VideoToolbox, run Gallinor on the macOS host (non-Docker).

#### Windows: NVIDIA NVENC via Docker Desktop (WSL2)

##### Requirements (Windows host)

- Docker Desktop with **WSL2 backend** enabled (GPU support in Docker Desktop is
  Windows + WSL2 only). You can run `docker` / `docker compose` from PowerShell;
  WSL2 is used under the hood by Docker Desktop.
- An NVIDIA GPU and an up-to-date NVIDIA Windows driver that supports WSL2 GPU
  paravirtualization (GPU-PV).
- Up-to-date WSL kernel (`wsl --update`).

##### Validate GPU access (before touching Gallinor)

From PowerShell (or from WSL), this should print NVIDIA-SMI output:

```bash
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi
```

If this fails, fix host setup before proceeding (Docker Desktop WSL2 backend,
WSL kernel update, NVIDIA driver).

##### Enable GPU for Gallinor containers (Compose)

Add the GPU reservation to any service that might execute `ffmpeg` with NVENC.
For maximum flexibility (including “sequential mode from Docker”), add it to
both:

- `gallinor-app` (runs the command you invoke)
- `gallinor-worker` (runs queued jobs)

Important: Do **not** add NVIDIA GPU reservations to the base
`docker-compose.yml` shown above, because it will fail on machines without the
NVIDIA runtime/driver (e.g., macOS). Keep GPU config in
`docker-compose.nvidia.yml`; the deploy scripts try it by default and fall back
to CPU-only, and you can disable it explicitly with `--no-nvidia` /
`GALLINOR_DOCKER_GPU=none`.

This plan uses the Compose Deploy specification device reservation:

- `capabilities: [gpu]` is required (Compose errors without it)
- use `count: all` to expose all GPUs, or replace with `device_ids: ['0']` etc.

Notes:

- GPU access only helps if the container’s `ffmpeg` supports NVENC (see
  “Recommended Dockerfile snippet” above).
- If you later want to prevent GPU contention between `gallinor-app` and
  workers, switch to explicit `device_ids` per service.
- No gallery file movement is required; bind-mount a Windows path (e.g.
  `C:\...`) via `GALLINOR_GALLERY_PATH` as usual.

##### Ensure `ffmpeg` in the image supports NVENC

Inside the built image, confirm `hevc_nvenc` exists:

```bash
docker compose run --rm gallinor-app ffmpeg -hide_banner -encoders | grep -i nvenc
```

If `hevc_nvenc` is missing, update the Docker image build to install an
NVENC-enabled `ffmpeg` (or build it accordingly).

### 7.3 Deploy Scripts (Hybrid Gallery Path Support)

Both deploy scripts support three gallery path invocation styles with priority:
CLI argument → ENV var → default.
They also accept an optional leading `--no-nvidia` flag to force CPU-only mode.

**`scripts/deploy.sh`** (macOS/Linux)

```bash
#!/usr/bin/env bash

# NVIDIA compose override is enabled by default (best performance when available).
# Disable with --no-nvidia or set GALLINOR_DOCKER_GPU=none.
USE_NVIDIA=1
if [[ "${1:-}" == "--no-nvidia" ]]; then
    USE_NVIDIA=0
    shift
fi
if [[ "${GALLINOR_DOCKER_GPU:-}" == "none" ]]; then
    USE_NVIDIA=0
fi

# Resolve gallery path (priority: CLI arg > ENV var > default)
if [[ -n "${1:-}" ]]; then
    GALLERY_PATH="$1"
elif [[ -n "$GALLINOR_GALLERY_PATH" ]]; then
    GALLERY_PATH="$GALLINOR_GALLERY_PATH"
else
    GALLERY_PATH="./gallery"
fi
export GALLINOR_GALLERY_PATH="$GALLERY_PATH"

# Compose files (GPU config is optional)
COMPOSE_ARGS=(-f docker-compose.yml)
if [[ "$USE_NVIDIA" -eq 1 ]]; then
    COMPOSE_ARGS+=(-f docker-compose.nvidia.yml)
fi

# Read container CPU limit by running nproc inside container
# Modern Docker (cgroup v2): nproc correctly reflects container limits
N_CORES=$(docker compose "${COMPOSE_ARGS[@]}" run --rm gallinor-worker nproc 2>/dev/null) || {
    if [[ "$USE_NVIDIA" -eq 1 ]]; then
        echo "NVIDIA override failed; retrying CPU-only. Use --no-nvidia to skip this probe."
        USE_NVIDIA=0
        COMPOSE_ARGS=(-f docker-compose.yml)
        N_CORES=$(docker compose "${COMPOSE_ARGS[@]}" run --rm gallinor-worker nproc)
    else
        exit 1
    fi
}

# Same formula as CLI: max(1, min(nCores / 4, floor(nCores / 8) + 2))
REPLICAS_A=$((N_CORES / 4))
REPLICAS_B=$((N_CORES / 8 + 2))
if ((REPLICAS_A < REPLICAS_B)); then
    REPLICAS=$REPLICAS_A
else
    REPLICAS=$REPLICAS_B
fi
if ((REPLICAS < 1)); then
    REPLICAS=1
fi

echo "Starting with $REPLICAS workers for $N_CORES cores, processing $GALLERY_PATH"

# Start containers with correct worker count
docker compose "${COMPOSE_ARGS[@]}" up -d --scale gallinor-worker=$REPLICAS
```

**`scripts/deploy.bat`** (Windows)

```bat
@echo off

REM NVIDIA compose override is enabled by default (best performance when available).
REM Disable with --no-nvidia or set GALLINOR_DOCKER_GPU=none.
set USE_NVIDIA=1
if /i "%~1"=="--no-nvidia" (
    set USE_NVIDIA=0
    shift
)
if /i "%GALLINOR_DOCKER_GPU%"=="none" set USE_NVIDIA=0

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

REM Compose files (GPU config is optional)
set COMPOSE_ARGS=-f docker-compose.yml
if "%USE_NVIDIA%"=="1" set COMPOSE_ARGS=%COMPOSE_ARGS% -f docker-compose.nvidia.yml

REM Read container CPU limit by running nproc inside container
REM Modern Docker (cgroup v2): nproc correctly reflects container limits
for /f "delims=" %%i in ('docker compose %COMPOSE_ARGS% run --rm gallinor-worker nproc 2^>nul') do set N_CORES=%%i

if not defined N_CORES (
    if "%USE_NVIDIA%"=="1" (
        echo NVIDIA override failed; retrying CPU-only. Use --no-nvidia to skip this probe.
        set USE_NVIDIA=0
        set COMPOSE_ARGS=-f docker-compose.yml
        for /f "delims=" %%i in ('docker compose %COMPOSE_ARGS% run --rm gallinor-worker nproc 2^>nul') do set N_CORES=%%i
    )
)

if not defined N_CORES (
    echo Error: Could not detect container CPU count
    exit /b 1
)

REM Calculate optimal replicas
REM Pass N_CORES as a parameter to avoid environment variable issues
for /f "delims=" %%i in ('powershell -NoLogo -NoProfile -Command "& { param([int]$c); [Math]::Max(1, [Math]::Min([int]($c / 4), [int]($c / 8) + 2)) } -c %N_CORES%"') do set REPLICAS=%%i

if not defined REPLICAS (
    echo Error: Could not calculate worker replica count
    exit /b 1
)

echo Starting with %REPLICAS% workers for %N_CORES% cores, processing %GALLERY_PATH%

REM Start containers with correct worker count
docker compose %COMPOSE_ARGS% up -d --scale gallinor-worker=%REPLICAS
```

### 7.4 Deployment Commands

Three invocation styles supported (all work identically):

**Style 1: Command-line argument** (explicit)

```bash
# macOS/Linux
./scripts/deploy.sh ~/Photos/vacation-2024

# Windows
scripts\deploy.bat C:\Users\User\Pictures\vacation-2024
```

#### Disable NVIDIA GPU (force CPU-only)

```bash
# macOS/Linux
./scripts/deploy.sh --no-nvidia ~/Photos/vacation-2024

# Windows
scripts\deploy.bat --no-nvidia C:\Users\User\Pictures\vacation-2024
```

**Style 2: Environment variable** (flexible, works with .env files)

```bash
# macOS/Linux
GALLINOR_GALLERY_PATH=~/Photos/vacation-2024 ./scripts/deploy.sh

# Windows
set GALLINOR_GALLERY_PATH=C:\Users\User\Pictures\vacation-2024
scripts\deploy.bat
```

#### Disable NVIDIA GPU via environment variable (force CPU-only)

```bash
# macOS/Linux
GALLINOR_DOCKER_GPU=none GALLINOR_GALLERY_PATH=~/Photos/vacation-2024 ./scripts/deploy.sh

# Windows
set GALLINOR_DOCKER_GPU=none
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

---

## Phase 8: App Entrypoint Refactoring

### 8.1 Create Kernel

Create `src/Kernel.php` to handle bundle loading and configuration (Standard
Symfony Pattern):

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

Refactor `app.php` to boot the Kernel and use the FrameworkBundle Application
for automatic command discovery.

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

**Manual DI removed**: All commands auto-discovered via container, no manual
wiring needed in app.php.
**Note**: `config/bundles.php` must exist for FrameworkBundle/Messenger/Doctrine
bundles to load.

---

## Testing Strategy

### Unit Tests

- `ProcessImageHandlerTest` - Test handler logic with mock CqLevelCalculator
- `CleanupCommandTest` - Test cleanup command
- `JobSchemaManagerTest` - Test schema creation + version reset
- `WorkerHeartbeatTest` - Test heartbeat insert/update logic

### Integration Tests

- Use real `CqLevelCalculator` with small test JPEGs in vfsStream
- Test parallel execution with 2-3 workers using file-based SQLite (shared DB
  across processes)
- Verify result collection, progress updates, and resume functionality
- Test worker crash scenarios and retry logic

**Edge Case Note**: Progress bar may stall momentarily during retries because
failed jobs create new DB rows (same path, different ID). We filter duplicates
via `$seenPaths`, so `LIMIT 20` query may return fewer than 20 actual
completions. This is acceptable behavior.

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

1. **Docker Compose + Image** - Dockerfile, compose file, deploy scripts; verify
   toolchain inside image and determine Linux `ssimulacra2` binary name
2. **Platform** - Enable Linux in `NativePlatform`, hard-code Linux
   `ssimulacra2` mapping based on Docker check
3. **Dependencies** - Install framework, messenger, doctrine-bundle,
   doctrine-messenger, dbal, config, dotenv
4. **Framework Setup** - Create Kernel, `config/bundles.php`,
   `config/services.php`, `config/packages/doctrine.php`,
   `config/packages/messenger.php`, `.env`, refactor app.php
5. **Database** - `DatabasePragmaConfigurator`, `JobSchemaManager`, schema
   version/heartbeat tables
6. **Messages & Handlers** - ProcessImageMessage, ProcessArwMessage,
   ProcessImageHandler, ProcessArwHandler, WorkerHeartbeat, heartbeat callback
   in CqLevelCalculator
7. **Serialization & Filtering** - Add ImageFile JSON, add `AvifFilter::All` and
   adjust collector
8. **Command Changes** - Modify Squeeze (parallel enablement, resume, liveness,
   cleanup, pre-flight worker check), add cleanup command
9. **Testing** - Unit tests (schema manager, heartbeat, handler, cleanup),
   integration tests (file-based SQLite)
10. **Final Review** - Test host and Docker modes end-to-end

---

## Next Steps

1. Implement Phase 1-5 (core functionality)
2. Test host (sequential) mode locally
3. Implement Phase 6-7 (Docker deployment)
4. Add tests
5. Update documentation
6. Performance benchmarking on M1 Pro vs Ryzen 7900X
