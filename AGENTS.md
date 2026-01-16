# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## Commands

### Development Commands

```shell
# Install dependencies
composer install

# Run the application
php app.php help                    # Show all available commands
php app.php videos:squeeze <path>   # Re-encode videos to HEVC with quality-based bitrate
php app.php videos:rename <path>    # Rename .optimal.mp4 files to replace originals
php app.php images:squeeze <path>   # Convert JPEGs to AVIF and archive ARW files
```

### Code Quality Commands

```shell
# Full CI pipeline
# Run this after completing any work to verify code quality
just ci

# Individual quality checks
just cbf              # Fix code style issues (PHP_CodeSniffer with Doctrine standard)
just stan             # Run PHPStan static analysis (max level)
just require-check    # Check for unused/misplaced dependencies
just security-check   # Check for security vulnerabilities (composer audit)
just composer-validate
```

### Testing Commands

```shell
# Run all tests
just test
# or: php vendor/bin/phpunit

# Run specific test
php vendor/bin/phpunit tests/Unit/Video/Domain/VideoFileTest.php

# Run tests with coverage
php vendor/bin/phpunit --coverage-text

# Check coverage thresholds (requires coverage.xml to exist)
php vendor/bin/phpunit-coverage-check coverage.xml 100
```

### Architecture Commands

```shell
# Rector (PHP 8.5 automated migrations)
php vendor/bin/rector process
```

## Architecture

This is a PHP 8.5 CLI application for optimizing media files (videos and images) while maintaining quality. The project follows a clean architecture pattern with strict domain separation.

### Directory Structure

```
src/
├── Images/          # Image processing (JPEG→AVIF, ARW archival)
├── Video/           # Video processing (MP4→HEVC with VMAF quality check)
└── Shared/          # Cross-cutting concerns (filesystem, platform, CLI helpers)
```

Each module follows DDD layering:
- `Domain/` - Core business logic, entities, and domain services
- `Infrastructure/` - External system integrations (FFmpeg, external tools)
- `Ui/Cli/` - Symfony Console commands

### Key Architecture Patterns

**Dependency Injection**: Manual constructor injection in `app.php` (no DI container). Commands receive all dependencies via constructor, with domain objects created lazily in `__invoke` methods.

**Readonly Domain**: All domain classes are marked `readonly` - immutable after construction.

**Quality-Based Encoding**:
- Video: Binary search for optimal CRF to achieve VMAF ≥ 90
- Images: Binary search for optimal CQ level to achieve SSIMULACRA2 ≥ 85

**Platform Abstraction**: `Platform` class (`src/Shared/Domain/Platform.php`) handles OS differences (macOS/Windows) for tool detection and core counting.

**External Tool Integration**: All external tools (FFmpeg, libavif, ssimulacra2, exiftool, xz) are invoked via `Symfony\Component\Process\Process` and located through `Platform::findTool()`.

### Important Constraints

- **PHP 8.5+ only** - Uses modern PHP features extensively
- **macOS and Windows only** - `Platform` constructor throws on unsupported OS
- **No exceptions in domain logic** - Errors are captured and returned via result objects (e.g., `ImageProcessorResult`)
- **Static analysis at max level** - PHPStan is configured to max level with strict analysis

### Testing

Tests are organized by module under `tests/Unit/`. Use `FsTestCase` for filesystem-related tests - it provides virtual filesystem isolation via `vfsstream`. Test doubles (mocks) use Mockery.

### Adding New Commands

1. Create command class in `src/{Module}/Ui/Cli/`
2. Add `#[AsCommand(name: '...')]` attribute
3. Implement `__invoke(OutputInterface $output, ...): int` method
4. Wire dependencies in `app.php` constructor and add to `$app->addCommands([])`

### Testing Best Practices

**Virtual Filesystem**: Use `FsTestCase` for all filesystem-dependent tests to ensure isolation and avoid real IO operations. It provides a vfsStream root via `$this->root` and `$this->vfsUrl()` helper.

**Test Doubles**: Use Mockery for mocking interfaces. Domain tests should use mocks for infrastructure dependencies (e.g., `Encoder`, `ProcessExecutor`).

**Isolation**: Tests should not depend on real temp directories, system state, or external tools. The `InMemoryProcessExecutor` test double should track file sizes without writing actual files.

**Helper Methods**: Private helper methods that are not named `test_*` and do not reference `$this` should be declared `static`. This is enforced by PHP_CodeSniffer.

## Technical Debt & Refactoring Opportunities

> **Note**: This section only documents remaining work. Completed refactoring items are removed from this file.

### Test IO Isolation Issues (Priority: Medium)

**Issue**: Several tests perform real filesystem I/O instead of using virtual filesystem isolation, making tests slower and less reliable:

1. **VideoProcessorTest** uses real temp directory (`sys_get_temp_dir()`)
2. **InMemoryProcessExecutor** is misnamed - it actually writes real files via `file_put_contents()` instead of being truly in-memory
3. **VideoFile domain objects** use real paths, coupling domain logic to filesystem

**Current State**:
- Tests create real files in temp directory during execution
- `InMemoryProcessExecutor` parses shell commands to extract file paths and writes real files
- ImageFileTest properly uses vfsStream (good reference implementation)

**Refactoring Options**:
1. **Extend FsTestCase to VideoProcessorTest** - Replace `sys_get_temp_dir()` with vfsStream URLs
2. **Make InMemoryProcessExecutor truly in-memory** - Track file sizes in array without writing, mock the file size checks
3. **Decouple VideoFile from filesystem** - Consider making `hasOptimized()` an interface or moving it to an infrastructure service
4. **Create ProcessResult stub that tracks virtual files** - Keep file size tracking isolated from real filesystem

**Recommended Approach**: Start with VideoProcessorTest refactoring to use vfsStream, then update InMemoryProcessExecutor to track file sizes in memory without writing real files. This aligns with existing vfsStream usage in ImageFileTest.

**Files Affected**:
- `tests/Unit/Video/Domain/VideoProcessorTest.php`
- `tests/Unit/Video/Domain/InMemoryProcessExecutor.php`
- `src/Images/Domain/ImageFile.php` (line 31: `hasOptimized()` method uses `file_exists()`)

**Reference Implementation**: `tests/Unit/Images/Domain/ImageFileTest.php` (lines 81-98) shows proper vfsStream usage with `FsTestCase`
