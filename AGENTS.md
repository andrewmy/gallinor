# AGENTS.md

This file provides guidance to AI agents when working with code in this
repository.

## Project Overview

Gallinor (Gallery Minor) is a PHP 8.5 CLI tool for reducing video and image
gallery sizes while maintaining quality. It supports:

- **Videos**: Re-encode to HEVC (H.265) with adaptive bitrate, hardware
  acceleration (VideoToolbox/NVENC)
- **Images**: Convert JPEGs to HEIC with quality-based
  encoding, optional parallel JPEG worker-pool mode, archive ARW files with xz
  compression

Before every task implementation, interview the user until everything is clear.

Every finished task must end in an update to this file if necessary.

## Commands

### Development

```bash
composer install              # Install dependencies
just ci                       # Full CI pipeline (validate, audit, analyze, lint, stan, test)
just cbf                      # Fix code style issues
just markdown                 # Lint Markdown files (via markdownlint-cli2; config: .markdownlint-cli2.jsonc)
just stan                     # Run PHPStan static analysis (level max)
just test                     # Run all tests
just smoke                    # Run smoke tests (real CLI + toolchain + localhost worker IPC)
```

### Application Usage

```bash
php app.php help                    # Show all available commands
```

**Video workflow** (2-step process):

```bash
php app.php videos:squeeze <path>   # Encode videos to HEVC (creates .optimal.mp4 files)
php app.php videos:rename <path>    # Replace originals with optimized files
```

**Image workflow** (2-step process):

```bash
php app.php images:squeeze <path> [--parallel] [--concurrency=N | --adaptive-concurrency=N] [--worker-max-jobs=N] [--job-timeout=SECONDS]  # Convert JPEGs to HEIC by default; ARW archival stays sequential
php app.php images:remove-originals <path>   # Remove originals after conversion
```

## Architecture

This is a PHP 8.5 CLI application for optimizing media files while maintaining
quality. The project follows clean architecture with strict domain separation.

### Bounded Contexts

- **Video/** - Video processing (MP4→HEVC with VMAF quality check)
- **Images/** - Image processing (JPEG→HEIC and ARW archival)
- **Shared/** - Cross-cutting concerns (filesystem, platform, CLI helpers)

Each context follows DDD layering:

- `Domain/` - Core business logic, entities, domain services
- `Infrastructure/` - External system integrations (FFmpeg, external tools)
- `Ui/Cli/` - Symfony Console commands

### Key Patterns

**Dependency Injection**: Manual constructor injection in `app.php` (no DI
container). Commands receive dependencies via constructor; domain objects
created lazily in `__invoke` methods.

**Readonly Domain**: All domain classes are `readonly` - immutable after
construction.

**Quality-Based Encoding**:

- Video: Binary search for optimal CRF to achieve VMAF ≥ 90
- Images:
  - JPEG→HEIC: coarse+fine quality search with early stop when score is in
    `[threshold, threshold + 1]` (threshold 85)

**Platform Abstraction**: `Platform` class handles OS differences
(macOS/Windows) for tool detection and core counting.

**External Tool Integration**: All external tools (FFmpeg, libheif,
ssimulacra2, exiftool, xz) invoked via `ProcessExecutor` and located through
`Platform::findTool()`.
Video quality checks require FFmpeg `libvmaf` filter availability (not
`vmafmotion` alone).

**Images rotation**: JPEGs with EXIF orientation are normalized via FFmpeg
before QC. FFmpeg normalization runs with `-noautorotate` so Gallinor applies
exactly one explicit EXIF-orientation transform (no double-rotation drift).
Optimized outputs bake rotation into pixels and force
Orientation=1 in metadata to avoid viewer inconsistencies.
ExifTool metadata-write steps retry once on transient temp-file write/rename
errors and include ExifTool stderr in thrown error messages for diagnosis.
Metadata read/write invocations use ExifTool `-m` mode to tolerate minor
vendor-parser warnings.
After orientation/dimension rewrites, Gallinor re-applies critical capture tags
(`GPSLatitude*`, `GPSLongitude*`, `GPSAltitude`, `ExifIFD:ColorSpace`) from
source before strict verification, and additionally projects GPS coordinates
into HEIC-friendly containers (`QuickTime:GPSCoordinates`,
`Keys:GPSCoordinates`, `ItemList:GPSCoordinates`, `UserData:GPSCoordinates`
and XMP GPS projection tags) when source GPS is available.
ExifTool filename charset on Windows is selected adaptively:
use `-charset filename=UTF8` only when input paths are valid UTF-8; otherwise
omit charset override to preserve legacy codepage paths.
For ExifTool write operations on Windows, file paths are normalized to native
separators and resolved through `realpath()` when possible before invocation.

**AQ note**: Video NVENC `-aq-strength` is NVENC-specific and unrelated to x265
`aq-strength` (0.0–3.0). Image HEIC encoding uses libheif + x265 params via
`heif-enc -p x265:...` (pinned defaults: `aq-mode=2`, `aq-strength=1.0`).
HEIC encode/decode subprocesses run without Symfony's default 60s timeout to
avoid false timeouts on large images. HEIC decode uses `heif-dec`.

**Parallel JPEG mode**: `images:squeeze` can run JPEG optimization through an
internal master/worker pool (`images:squeeze:worker`) over localhost NDJSON
messages using `symplify/easy-parallel` primitives for worker lifecycle and
transport. `--job-timeout` is inactivity-based (no worker message), `--dry-run`
stays single-process, and ARW archival remains sequential.

Parallel execution uses a shared worker-pool orchestrator in
`src/Images/Ui/Cli/Parallel/ParallelWorkerPoolOrchestrator.php`.
Console tracing/panel rendering is shared via
`src/Images/Ui/Cli/Parallel/ParallelConsoleTelemetry.php`.
Parallel option validation and fixed/adaptive worker-plan selection are shared
via
`src/Images/Ui/Cli/Parallel/ParallelExecutionPlanResolver.php`.
Adaptive ramp runtime state in orchestrator is carried by DTO
`src/Images/Ui/Cli/Parallel/AdaptiveConcurrencyState.php` (avoid untyped
array/hash state in hot loop logic).
When stopping workers through `ProcessPool::tryQuitProcess()`, only issue quit
for workers that already completed HELLO/bind; pre-HELLO quit can trigger
EasyParallel encoder-initialization fatals.
Worker startup/bind robustness: unconnected workers are recycled after a
connect-timeout window, and HELLO bind failures remove the worker from the live
set so deficit-respawn logic can recover instead of stalling progress.
Workers are marked connected only after `bindConnection()` succeeds (not on
HELLO receipt alone), and unexpected worker exits count as system errors even
when the process exit code is `null` (important on Windows where `null` exits
can otherwise cause endless respawn loops without progress).
Worker/master NDJSON payload serialization is centralized in
`src/Images/Ui/Cli/Parallel/ParallelJsonEncoder.php` and uses
`JSON_INVALID_UTF8_SUBSTITUTE` to tolerate non-UTF-8 bytes from tool stderr on
Windows (preventing worker crash at message encode).
Use Symfony verbosity flags for worker-pool tracing:
`-v` (lifecycle), `-vv` (dispatch/requeue details), `-vvv` (status-frame level).
At `-vv` and above, the parallel command renders a live per-worker status panel
below the progress bar when the terminal supports console sections.
In panel mode, trace updates are coalesced with worker-state refreshes to avoid
trace-only redraw spam (for example repeated `Workers` headers at `-vvv`).
Parallel worker policy:

- no `--concurrency` and no `--adaptive-concurrency`: fixed safe worker count
  from `ParallelConcurrency::defaultFromCores()`
- `--concurrency=N`: fixed `N` workers
- `--adaptive-concurrency=N`: start from safe workers and ramp up to max `N`
  while throughput gains remain meaningful

The current adaptive gain threshold is a code constant in
`src/Images/Ui/Cli/Parallel/ParallelWorkerPoolOrchestrator.php`
(`ADAPTIVE_MIN_THROUGHPUT_GAIN_RATIO`).
Worker status phases exposed in telemetry are:
`prepare -> encode -> decode -> score -> decision -> finalize -> metadata`.
These phase/status payload fields are currently for operator visibility only
(progress/panel output). Orchestration control flow (timeouts, retries, worker
recycling, completion) does not branch on phase names.

**Timing accounting**: `images:squeeze` reports archiving time using direct
wall-clock measurement around ARW archival, not derived residual math from
other timing buckets.

### Important Constraints

- **PHP 8.5+ only** - Uses modern PHP features extensively
- **macOS and Windows only** - `Platform` constructor throws on unsupported OS
- **No exceptions in domain logic** - Errors are captured and returned via
  result objects (e.g., `ImageBatchResult`)
- **Static analysis at max level** - PHPStan is configured to max level with
  strict analysis
- **Video rotation handling**: Videos with Display Matrix rotation metadata
  (e.g., -90°) are detected via `side_data_list` in `show_streams`. NVENC cannot
  properly handle rotation, so `encoderForFile()` switches to CPU encoder
  (`libx265`) for rotated videos. CPU encoder bakes rotation into pixels
  (1920x1080→1080x1920), ensuring correct playback orientation and direct VMAF
  comparison without scaling needed.

## Agent Guidelines (General)

- **Docs must match code**: When updating docs, ensure snippets and field names
  reflect actual classes and data shapes.
- **Parameter nullability**: Prefer non-nullable parameters by default (not
  only callbacks). Introduce nullable args only when `null` is a real semantic
  state. If an input is optional for a caller, prefer explicit defaults at the
  call site (for example, a no-op closure) instead of widening callee
  signatures with `|null`.
- **Meaningful changes require tests + notes**: Prefer writing a failing unit
 test first (“red-green”), then implement the change. If behavior/tooling/docs
  change, update [AGENTS.md](AGENTS.md) and [README.md](README.md) in the same
  PR.
- **Cross-platform tools**: Verify binary names per OS (macOS/Windows/Linux),
  and document any platform-specific mapping when introducing external tools.
- **Metadata verification triage**: For `Metadata verification failed` in image
  flows, use test-first updates in
  `tests/Unit/Images/Domain/StrictMetadataVerifierTest.php` (add failing case),
  then adjust `src/Images/Domain/StrictMetadataVerifier.php` with a short
  rationale comment.
  - Ignore only non-portable metadata families (container/runtime/vendor/private
    tags such as `System:*`, `QuickTime:*`, `JFIF:*`, `JSON:*`,
    `MakerUnknown:*`,
    `ExifIFD:MakerNoteUnknown*`, `XMP-crs:MaskGroupBasedCorr*`,
    `XMP-crs:RetouchArea*`,
    `XMP-crs:LookParameters*`,
    `XMP-photoshop:CameraProfilesPerspectiveModelVignetteModel*`,
    `XMP-alienexposure:*`,
    `XMP-xmpNote:*`,
    `XMP-exif:ExposureCompensation`,
    `Samsung:*`,
    vendor XMP blocks, `ExifIFD:Exif_0xNNNN`,
    JPEG other-image pointer tags (`IFD0:OtherImage*`),
    dimension projection tags (`ExifIFD:ImageWidth`, `ExifIFD:ImageHeight`,
    `XMP-tiff:ImageWidth`, `XMP-tiff:ImageHeight`),
    and derived brightness metric (`ExifIFD:BrightnessValue`).
  - Treat `ExifIFD:ShutterSpeedValue` rewrites as equivalent when
    `ExifIFD:ExposureTime` is unchanged.
  - Treat `GPS:GPSAltitude` and `GPS:GPSHPositioningError` rewrites as
    equivalent when numeric values differ only by formatting/rounding
    (tolerance: `<= 0.01 m`).
  - If source GPS values are placeholders only (for example empty/`Unknown ()`/
    `undef`), treat them as missing-at-source and do not fail destination for
    absent GPS core tags.
  - For missing `ExifIFD:ColorSpace`, accept equivalent `*:ColorSpace`
    projection when value is unchanged.
  - Keep core capture/user metadata strict by default (`EXIF:*` camera/exposure,
    `DateTime*`, GPS, lens/make/model). Do not relax these without explicit
    product decision.
- **Orchestration**: Current image parallelism uses an in-app worker pool for
  single-machine acceleration. Prefer external orchestration (e.g., Docker) when
  scaling beyond a single machine or when packaging toolchains.
- **SQLite in Docker**: Use named volumes for SQLite by default to avoid
  macOS/Windows bind-mount slowness; bind mount only for debugging.
- **Long-running work**: Avoid per-file timeouts for media processing; if
  liveness is needed, use heartbeat-style progress signals.
- **Keep constraints current**: If you change platform support (e.g., add Linux
  for Docker), update the “Important Constraints” section to reflect reality.

## Testing

Tests organized by module under `tests/Unit/`. Use `FsTestCase` for
filesystem-related tests (virtual filesystem via vfsstream). Test doubles use
Mockery.

Smoke tests live in `tests/Smoke/` and are executed explicitly via
`just smoke` (`php vendor/bin/phpunit tests/Smoke`) using the main
`phpunit.xml` config. Do not add separate PHPUnit config files for smoke tests.
Smoke tests are intentionally excluded from the default `just ci` suite.

### Current Status

**Unit suite (`just ci` / `just test`)**: 180 tests — passing, 0 incomplete,
0 warnings (as of 2026-02-16)

**Smoke suite (`just smoke`)**: 3 tests with real CLI invocations; environment
dependent and may skip when toolchain/localhost IPC is unavailable.

**Tested**:

- `VideoFile` — bitrate, resolution
- `VideoProcessor` — skip/dry-run/encode/retry
- `ImageFile` — paths and optimized-path detection
- `ImageFormat` — CLI parsing
- `ImageCollectionStats` — immutability, totals
- `ImageFileCollector` — filtering/skip-set behavior (via `ExifMetadata` stub)
- `ImageProcessorResult` — aggregation, savings
- `Ssimulacra2` — `fileSizeKb()` rounding
- `StrictMetadataVerifier` — diff rules
- `ParallelConcurrency` — default worker count formula
- `JobRetryPolicy` — one-time requeue behavior
- `ParallelWorkerPayloadHandler` — worker message parsing and batch mutation
- `ParallelTempDirectoryManager` — temp dir creation/pruning/removal
- `ParallelConsoleTelemetry` — panel redraw behavior and trace coalescing
- `ArchiveVerifier` — ARW/archive verification with real temp-dir coverage for
  `glob()` paths
- `RawArchiver` — Windows tar/xz flow including xz failure path

### Not Yet Testable (blocked by `final` classes)

- `ImageOptimizer` — depends on concrete `HeicCodec`/external tools; needs
  ports to stub encode/decode/QC
- Many CLI commands — construct tool wrappers inside `__invoke`, so unit tests
  often require real binaries on PATH

**Refactoring Options**: Extract ports (e.g. `ExifMetadata`, `PlatformApi`,
`ImageCodec`) and inject factories so unit tests can avoid real binaries.

**Note on `--dry-run`**: For destructive operations (e.g. removing originals),
`--dry-run` still runs filtering/verification that depends on external tools, so
it is intentionally not “no-tools-required”.

### Testable but Untested (quick wins)

- `VideoFinder` — pure logic, uses `Encoder` interface
- `VideoSummary` — pure formatting/printing
- `CliHelper` — pure formatting + link formatting
- `Timing` — pure formatting + value object methods (avoid time-sensitive
  assertions)
- `ArchiveVerificationResult` — pure aggregation/counting
- `ImageCollection` — pure value object defaults

### Coverage Growth Strategy

**Quick wins**: `VideoFinder`, CLI helper/value objects (`CliHelper`, `Timing`,
`VideoSummary`), small DTOs (`ArchiveVerificationResult`, `VideoProcessResult`,
`ImageProcessingResult`).

**Medium effort**: Extract interfaces for final classes, add integration tests
for actual tool execution.

**Long term**: Test doubles for external tools, property-based testing for
calculations.

### Testing Best Practices

**Virtual Filesystem**: Use `FsTestCase` for filesystem-dependent tests to
ensure isolation. Provides vfsStream root via `$this->root` and
`$this->vfsUrl()` helper.

**Test Doubles** (prefer in order):

1. Stub classes for domain interfaces (e.g., `StubPlatform`)
2. `TestHandler` (Monolog) for logging — allows log inspection
3. Mockery only when stubs unavailable

**Isolation**: Tests should not depend on real temp directories, system state,
or external tools. `InMemoryProcessExecutor` test double should track file sizes
without writing actual files.

**Test Doubles Reference**:

- `StubPlatform` (`tests/Shared/StubPlatform.php`) — Simple stub with
  configurable properties
- `TestHandler` (Monolog) — Captures log records for inspection
- `InMemoryProcessExecutor` (`tests/Shared/InMemoryProcessExecutor.php`) —
  Simulates process execution

**Helper Methods**: Private helpers not named `test_*` and not referencing
`$this` must be `static` (PHP_CodeSniffer enforcement).

**Key Pattern**: When mocking methods receiving dynamically-generated values
(like `uniqid()`), use `Mockery::any()` wildcard. Use `sys_get_temp_dir()` for
test file paths so `rename()` succeeds.

## CI/CD

GitHub Actions workflow runs on push/PR to main. Workflow should match `just ci`
command (validate, audit, analyze, lint, stan, test). Coverage gating is
defined in `justfile` (`coverage-check`) and
`.github/workflows/ci.yaml` (source of truth).

## Design docs

- [docs/README.md](docs/README.md) (index)
- [docs/QUALITY_SEARCH_UNIFICATION_PLAN.md](docs/QUALITY_SEARCH_UNIFICATION_PLAN.md)
- [docs/DOCKERIZATION_PLAN.md](docs/DOCKERIZATION_PLAN.md)

## Code Style

- PHP 8.5 with strict types
- Doctrine coding standard (via PHP_CodeSniffer)
- PHPStan level max
- Comments: Never explain *what* code does, only *why* if not obvious. Adjust
  naming instead.

**After each meaningful change**: Run `just ci`.

**Exception (Markdown-only changes)**: If you changed only `*.md` files, do
**not** run `just ci`; run `just markdown` instead.

**If CI fails on formatting**: Run `just cbf`, then re-run `just ci`.

## Adding New Commands

1. Create command class in `src/{Module}/Ui/Cli/`
2. Add `#[AsCommand(name: '...')]` attribute
3. Implement `__invoke(OutputInterface $output, ...): int` method
4. Use `App\Shared\Ui\Cli\CliHelper::startCommand()` for the standard Dry-run /
   Init-time prelude.
5. Wire dependencies in `app.php` constructor and add to `$app->addCommands([])`

## Technical Debt & Refactoring Opportunities

> This section only documents remaining work. Completed items are removed.

### Parallel CLI Telemetry Stability (Priority: Medium)

**Issue**: High-verbosity parallel output (`-vv`/`-vvv`) blends progress-bar
rendering, trace messages, and worker status panel updates. This can vary by
terminal/TTY behaviour and is currently covered mostly by manual checks.

**Recommended Approach**:

1. Add targeted integration coverage for console rendering paths (TTY vs
   non-TTY) for `images:squeeze --parallel`
2. Keep worker-panel redraws rate-limited and deduplicated; treat regressions as
   behaviour bugs, not cosmetic only
3. Consider adding a dedicated flag to disable worker panel while keeping
   verbose traces, for troubleshooting problematic terminals

### Test IO Isolation Issues (Priority: Medium)

**Issue**: Several tests perform real filesystem I/O instead of using virtual
filesystem isolation, making tests slower and less reliable.

**Affected Areas**:

- `VideoProcessorTest` uses real temp directory (`sys_get_temp_dir()`)
- `InMemoryProcessExecutor` misnamed — writes real files via
  `file_put_contents()` instead of being truly in-memory
- `VideoFile` domain objects use real paths, coupling domain logic to filesystem

**Recommended Approach**:

1. Decide per-test whether vfsStream is appropriate (anything using `glob()`,
   `rename()` across temp→target, or real tools may need real temp dirs or a
   refactor)
2. Make `InMemoryProcessExecutor` truly in-memory — track file sizes in arrays
   without writing
3. Keep `ArchiveVerifier` on real temp-dir tests for `glob()` paths unless
   archive discovery is abstracted behind an injectable filesystem port
