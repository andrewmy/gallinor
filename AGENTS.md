# AGENTS.md

This file provides guidance to AI agents when working with code in this
repository.

## Project Overview

Gallinor (Gallery Minor) is a PHP 8.5 CLI tool for reducing video and image
gallery sizes while maintaining quality. It supports:

- **Videos**: Re-encode to HEVC (H.265) with adaptive bitrate, hardware
  acceleration (VideoToolbox/NVENC)
- **Images**: Convert JPEGs to HEIC (default; AVIF optional) with quality-based
  encoding, archive ARW files with xz compression

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
php app.php images:squeeze <path>           # Convert JPEGs to HEIC by default, archive ARWs
php app.php images:remove-originals <path>   # Remove originals after conversion
```

**AVIF→HEIC migration** (OneDrive compatibility):

```bash
php app.php images:migrate-avif-to-heic <path>  # Convert existing AVIFs to HEIC (SSIMULACRA2 ≥ 90)
php app.php images:remove-avifs <path>          # Remove AVIFs when sibling HEIC exists
```

## Architecture

This is a PHP 8.5 CLI application for optimizing media files while maintaining
quality. The project follows clean architecture with strict domain separation.

### Bounded Contexts

- **Video/** - Video processing (MP4→HEVC with VMAF quality check)
- **Images/** - Image processing (JPEG→HEIC by default; AVIF optional, ARW archival)
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
  - JPEG→HEIC/AVIF: search minimal quality to achieve SSIMULACRA2 ≥ 85
  - AVIF→HEIC migration: search minimal quality to achieve SSIMULACRA2 ≥ 90

**Platform Abstraction**: `Platform` class handles OS differences
(macOS/Windows) for tool detection and core counting.

**External Tool Integration**: All external tools (FFmpeg, libheif, libavif,
ssimulacra2, exiftool, xz) invoked via `ProcessExecutor` and located through
`Platform::findTool()`.

**Images rotation**: JPEGs with EXIF orientation are normalized via FFmpeg
before QC. Optimized outputs bake rotation into pixels and force
Orientation=1 in metadata to avoid viewer inconsistencies.

**AQ note**: Video NVENC `-aq-strength` is NVENC-specific and unrelated to x265
`aq-strength` (0.0–3.0). Image HEIC encoding uses libheif + x265 params via
`heif-enc -p x265:...` (pinned defaults: `aq-mode=2`, `aq-strength=1.0`).

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
- **Meaningful changes require tests + notes**: Prefer writing a failing unit
  test first (“red-green”), then implement the change. If behavior/tooling/docs
  change, update `AGENTS.md`/`README.md` in the same PR.
- **Cross-platform tools**: Verify binary names per OS (macOS/Windows/Linux),
  and document any platform-specific mapping when introducing external tools.
- **Orchestration**: Prefer external orchestration (e.g., Docker) over in-app
  worker management when cross-platform process control would add complexity.
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

### Current Status

**75 tests, 18.85% line coverage** — passing, with 6 incomplete tests and 1
warning (as of 2026-02-07)

**Tested**:

- `VideoFile` — bitrate, resolution
- `VideoProcessor` — skip/dry-run/encode/retry
- `ImageFile` — paths, AVIF detection
- `ImageFormat` — CLI parsing
- `ImageCollectionStats` — immutability, totals
- `ImageFileCollector` — filtering/skip-set behavior (via `ExifMetadata` stub)
- `ImageProcessorResult` — aggregation, savings
- `Ssimulacra2` — `fileSizeKb()` rounding
- `StrictMetadataVerifier` — diff rules

### Not Yet Testable (blocked by `final` classes)

- `ImageOptimizer` — depends on concrete `HeicCodec`/external tools; needs ports to stub encode/decode/QC
- `ArchiveVerifier` — depends on `Platform` (final)
- Most CLI commands — construct tool wrappers inside `__invoke`, so unit tests require real binaries on PATH

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
command (validate, audit, analyze, lint, stan, test). Coverage threshold: 10%
(var/coverage.xml).

## Code Style

- PHP 8.5 with strict types
- Doctrine coding standard (via PHP_CodeSniffer)
- PHPStan level max
- Comments: Never explain *what* code does, only *why* if not obvious. Adjust
  naming instead.

**After each edit**: Run `just test`, `just stan`, and `just cbf`.

**Exception (Markdown-only changes)**: If you changed only `*.md` files, do
**not** run `just test`, `just stan`, or `just cbf`; run `just markdown`
instead.

## Adding New Commands

1. Create command class in `src/{Module}/Ui/Cli/`
2. Add `#[AsCommand(name: '...')]` attribute
3. Implement `__invoke(OutputInterface $output, ...): int` method
4. Wire dependencies in `app.php` constructor and add to `$app->addCommands([])`

## Technical Debt & Refactoring Opportunities

> This section only documents remaining work. Completed items are removed.

### Test IO Isolation Issues (Priority: Medium)

**Issue**: Several tests perform real filesystem I/O instead of using virtual
filesystem isolation, making tests slower and less reliable.

**Affected Areas**:

- `VideoProcessorTest` uses real temp directory (`sys_get_temp_dir()`)
- `InMemoryProcessExecutor` misnamed — writes real files via
  `file_put_contents()` instead of being truly in-memory
- `VideoFile` domain objects use real paths, coupling domain logic to filesystem
- `ArchiveVerifierTest` has 5 incomplete tests due to `glob()` not working on
  vfsStream paths
- `RawArchiverTest` has 1 incomplete test (needs better tar/xz simulation)
- `RawArchiver` (Windows flow) uses `rename()` from temp dir → target dir; can
  fail across filesystems/volumes

**Recommended Approach**:

1. Decide per-test whether vfsStream is appropriate (anything using `glob()`,
   `rename()` across temp→target, or real tools may need real temp dirs or a
   refactor)
2. Make `InMemoryProcessExecutor` truly in-memory — track file sizes in arrays
   without writing
3. Refactor `ArchiveVerifier` to avoid `glob()` (inject archive discovery /
   filesystem abstraction) so vfsStream works
4. Make `RawArchiver` robust to cross-filesystem moves (fallback to copy+unlink
   when `rename()` fails), then test it
