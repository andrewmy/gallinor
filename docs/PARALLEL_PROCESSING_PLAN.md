# Parallel Image Processing Plan (In-App Worker Pool)

## Summary

Add optional parallel JPEG processing to `images:squeeze` by having the main
process spawn a pool of worker PHP processes (master/worker), distribute JPEG
jobs, and aggregate results for progress + summaries.

This is a **single-machine** parallelisation strategy that works the same on:

- **Host mode** (macOS/Windows): workers are child PHP processes
- **Docker mode** (future default): workers are child PHP processes *inside the
  container*

No durable queue/database is introduced in v1; “resume” is best-effort via the
existing “skip if output exists” rules.

## Related plans

- [README.md](README.md) (index)
- [QUALITY_SEARCH_UNIFICATION_PLAN.md](QUALITY_SEARCH_UNIFICATION_PLAN.md)
  (quality probing/search mechanics this speeds up)
- [DOCKERIZATION_PLAN.md](DOCKERIZATION_PLAN.md) (Docker/toolchain direction;
  workers still spawn in-container)

## Why the previous Docker+Messenger plan was rejected

The previous plan (Symfony Messenger + Doctrine transport + SQLite WAL + Compose
worker replicas) was **eventually rejected** because it:

- introduces a full “app framework + queue” runtime into a small CLI
- expands operational surface (schema lifecycle, WAL/VACUUM, liveness, retries)
- shifts the project toward Linux-first platform support earlier than needed
- optimises for *durable distributed workloads* when we mostly need *faster
  single-machine batch processing*
- makes the “happy path” more complex (extra services/volumes/workers) even when
  the user just wants `php app.php images:squeeze ...`

If we later decide we truly need **multi-container distribution** or **durable
resume across runs**, revisiting the queue approach can make sense. For now, the
worker-pool model is the simplest “high leverage” win.

## Prior art (how PHP tools parallelise)

This design matches how common PHP dev tools parallelise CPU-bound “many files”
workloads:

- **PHPStan**: master process schedules “jobs” of files, spawns workers via
  `proc_open`, and coordinates them over a localhost TCP server using NDJSON.
- **Rector**: uses the same pattern (explicitly inspired by PHPStan), including
  worker recycling to control memory growth.
- **PHP_CodeSniffer**: supports `--parallel` but uses `pcntl_fork` (Unix-only),
  so it’s not a good model if we want Windows host support.

## Goals

- Speed up JPEG optimisation (encode + decode + SSIMULACRA2 QC) on multi-core
  machines.
- Keep sequential mode as default and stable.
- Keep cross-platform host support (macOS + Windows). Docker remains optional
  but is expected to become the default for toolchain/binaries.
- Keep the domain architecture intact (no domain exceptions; surface errors via
  result objects where possible).
- Avoid introducing “application server” infrastructure (durable queues, DB
  schema migrations) unless/until we need distribution.

## Non-goals (v1)

- Parallel ARW archival (keep sequential; avoids directory locking complexity).
- Distributed processing across multiple containers/machines.
- Durable queue-backed resume semantics that override filesystem reality.

## User-facing CLI changes

Extend `images:squeeze`:

- `--parallel`: enable worker pool for JPEG processing
- `--concurrency=N`: override worker count (default: auto)
- `--worker-max-jobs=N`: recycle a worker after N JPEGs (default: 50)
- `--job-timeout=SECONDS`: max allowed time with no worker message for a job
  (default: 3600)

Notes:

- `--dry-run` never spawns workers (it remains a pure “collect + report” run).
- ARWs remain processed sequentially by the main process after JPEGs are done.

## Concurrency default

When `--parallel` is enabled and `--concurrency` is not supplied:

```php
$n = max(1, min(intdiv($nCores, 4), intdiv($nCores, 8) + 2));
```

This is intentionally conservative to avoid oversubscribing the machine while
each job runs multiple external binaries.

## How `$nCores` is detected (today)

Gallinor already prints available cores via `Platform::nCores()`:

- macOS: `sysctl -n hw.ncpu`
- Windows: PowerShell
  `(Get-CimInstance -ClassName Win32_Processor).NumberOfCores`
- If detection fails: fallback to `1`

See `src/Shared/Infrastructure/NativePlatform.php`.

In Docker/Linux we will need to extend `NativePlatform` (or introduce a
Docker/Linux platform) because it currently supports only macOS and Windows.

## Architecture

### High-level flow

```text
┌────────────────────────────────────────────────────────────┐
│ images:squeeze --parallel (Master)                          │
│  - Collect JPEGs (existing collector + skip rules)          │
│  - Split into jobs (one JPEG per job in v1)                 │
│  - Spawn N workers (child PHP processes)                    │
│  - Dispatch jobs, aggregate results                         │
│  - Render progress bar + summaries                          │
│  - After JPEGs: archive ARWs sequentially (existing code)   │
└────────────────────────────────────────────────────────────┘
                          │
                          ▼
         ┌───────────────────────────────────────┐
         │ Worker pool (child PHP processes)      │
         │  - One job at a time                   │
         │  - Runs optimizer + codec + QC         │
         │  - Streams status + result back        │
         └───────────────────────────────────────┘
```

### IPC model

Use the same communication model as PHPStan/Rector:

- master starts a localhost TCP server on `127.0.0.1:0`
- workers connect to it and announce themselves
- messages are newline-delimited JSON (NDJSON)

This keeps the worker protocol trivial and avoids complicated shared-state.

## Implementation approach (reduce infra code)

Preferred: use `symplify/easy-parallel` to avoid implementing the pool,
TCP/NDJSON
plumbing, and worker lifecycle ourselves.

Expected new dependency:

```bash
composer require symplify/easy-parallel
```

Parallel mode depends on this package; sequential mode does not.

If we ever need to keep runtime deps minimal, we can replace it with a small
bespoke pool using `proc_open` + `stream_select` + NDJSON, but the library is
the default plan.

## Internal message protocol (NDJSON)

Workers and master communicate over a localhost TCP socket using newline
delimited JSON (one JSON object per line).

All messages include:

- `v` (int): protocol version (start with `1`)
- `type` (string): message type

### Worker → master

#### Hello

```json
{"v":1,"type":"hello","workerId":"...","pid":12345}
```

#### Status (optional; emitted during quality probing)

```json
{"v":1,"type":"status","workerId":"...","jobId":"...","path":"/a/b.jpg","quality":60,"score":88.1,"savedBytes":123456}
```

#### Result

Processed

```json
{"v":1,"type":"result","workerId":"...","jobId":"...","path":"/a/b.jpg","outcome":"processed","result":{"format":"heic","originalSize":123,"optimizedSize":45,"qualityValue":60,"qualityLabel":"q","qualityScore":88.1,"qcTime":1.23}}
```

Skipped

```json
{"v":1,"type":"result","workerId":"...","jobId":"...","path":"/a/b.jpg","outcome":"skipped","skipReason":"ReplacementNotSmaller"}
```

Errored

```json
{"v":1,"type":"result","workerId":"...","jobId":"...","path":"/a/b.jpg","outcome":"error","error":"ffmpeg failed: ..."}
```

#### Log (optional; for verbose/debug modes)

```json
{"v":1,"type":"log","level":"info","message":"...","context":{"jobId":"...","path":"/a/b.jpg"}}
```

### Master → worker

#### Job

```json
{"v":1,"type":"job","jobId":"...","path":"/a/b.jpg","format":"heic"}
```

#### Quit

```json
{"v":1,"type":"quit"}
```

## Worker design

### Internal worker command

Add a hidden internal command, e.g.:

- `images:squeeze:worker` (hidden)

Workers are not user-invoked; the master spawns them with arguments like:

- `--port=<port>`
- `--identifier=<id>`
- `--format=heic|avif`

### Worker responsibilities

For each job:

1. Construct `ImageFile` (path + `filesize`) and choose codec (HEIC/AVIF).
2. Call `ImageOptimizer::optimizeJpeg(...)` with a status callback.
3. Emit:
   - `status` events (quality probe, score, saved bytes), optional
   - a final `result` event:
     - `processed` with `ImageProcessingResult` fields
     - `skipped` with `CalculationSkipReason`
     - `error` with a safe error message

Workers should recycle after `--worker-max-jobs` to limit memory growth.

### Temp file isolation

Each worker must run with its own temp root to avoid collisions and simplify
crash cleanup.

Contract:

- Worker temp root:
  - Unix: `/tmp/gallinor-parallel/<workerId>/`
  - Windows: `%TEMP%\\gallinor-parallel\\<workerId>\\`
- Job temp directory:
  - `<workerTempRoot>/<jobId>/`
- Worker startup:
  - create worker temp root
  - set process temp env vars to worker temp root:
    - Unix: `TMPDIR`
    - Windows: `TMP`, `TEMP`
- Job execution:
  - create per-job temp dir
  - all transient files for that job must be scoped there
- Job completion (success/skip/error):
  - delete the per-job temp dir in a `finally` block
- Worker shutdown:
  - remove worker temp root when empty
- Master startup:
  - prune stale `gallinor-parallel/*` directories older than a retention window
    (default: 24h)

This works with the current `sys_get_temp_dir()` usage and ensures different
workers never share temp file namespaces.

### Timeouts

- Some images can legitimately take longer than 10 minutes because a single job
  may run multiple encodes/decodes and multiple SSIMULACRA2 computations.
- `--job-timeout` is therefore an “inactivity timeout”: it should be based on
  “no message received from the worker for this job”, not wall-clock time.
- Default is **3600 seconds**. Users can raise it (or disable it) for very
  large files; it still protects against “stuck forever” tool invocations.

## Master design

### What runs in parallel

- JPEG optimisation only (`processJpegs` path).

### What remains sequential

- ARW archival remains sequential in v1.

### Handling worker failure

- If a worker exits while holding an in-flight job:
  - re-queue that job once
  - spawn a replacement worker (unless global error limit reached)
- Stop the run if “system errors” exceed a limit (default: 50), mirroring the
  “error limit” concept used in similar tools.

### Resume semantics

No queue/database is introduced. Resume remains filesystem-based:

- `ImageFileCollector` already skips JPEGs when the target (`.heic`/`.avif`)
  exists (`OptimizedFilter::OnlyWithout`).
- This makes re-running safe and naturally resumable after a crash.

## Docker impact (expected future)

As we lean more into Docker to ship/standardise external binaries:

- The worker-pool architecture remains unchanged.
- We run a **single container** for a run; the master spawns workers as child
  processes within the container.
- See [DOCKERIZATION_PLAN.md](DOCKERIZATION_PLAN.md) for the Docker/toolchain
  rollout plan.
- If we later want to distribute across multiple containers, that’s the point
  where a durable queue (Messenger/SQLite/Redis/etc.) may become worthwhile.

## Testing plan

### Unit tests (no external tools)

- Scheduler/concurrency:
  - core → worker count default
  - `--concurrency` override
- Worker/master state handling:
  - in-flight job re-queued on worker exit
  - error limit stops the run
- NDJSON framing (if any custom code is introduced):
  - partial reads
  - multiple messages in one chunk
  - invalid JSON produces a controlled error

### Manual validation (with tools available)

- `php app.php images:squeeze --dry-run --parallel <dir>` does not spawn workers
- `php app.php images:squeeze --parallel --concurrency=2 <dir>` produces the
  same outputs as sequential, but faster
- re-run skips already optimised files

## Rollout plan

1. Add CLI flags and keep behaviour identical unless `--parallel` is set.
2. Parallelise JPEG processing using `symplify/easy-parallel`.
3. Keep ARW archival sequential; revisit after JPEG parallelism is stable.
4. When Docker becomes the default distribution, keep the same worker model and
   focus Docker work on packaging the external binaries.
