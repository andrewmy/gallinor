# AGENTS.md

Concise development guide for this repository.

## Project Snapshot

Gallinor is a PHP 8.5+ CLI for reducing media size while preserving quality.

- Video: re-encode to HEVC (NVENC / Apple VideoToolbox / CPU fallback),
  validate with VMAF.
- Images: JPEG -> HEIC (SSIMULACRA2 threshold), archive ARW with xz.

Primary contexts:

- `src/Video/*`
- `src/Images/*`
- `src/Shared/*`

Architecture is clean/DDD-ish per context:

- `Domain/`
- `Infrastructure/`
- `Ui/Cli/`

## Daily Commands

```bash
composer install
just ci
just stan
just test
just smoke
just cbf
just markdown
php app.php help
```

Common app flows:

```bash
php app.php videos:squeeze <path>
php app.php videos:rename <path>

php app.php images:squeeze <path> [--parallel] [--concurrency=N | --adaptive-concurrency=N]
php app.php images:remove-originals <path>
```

## Hard Constraints

- PHP 8.5+ with strict types.
- Native runtime targets: macOS + Windows (`Platform` guards this).
- Linux is supported via Docker workflows.
- Domain flows should report operational failures via result objects where
  already modeled (avoid widening exception-driven control flow).
- Keep constructor injection/manual wiring in `app.php` (no DI container).

## Video Rules To Preserve

- Quality gate: VMAF threshold is 90.
- Skip files that already have `.optimal.mp4` (default command behavior).
- Bitrate search behavior:
  - start from resolution base bitrate
  - adaptive upward retries on VMAF fail
  - downward probing on headroom
  - stop downward probing early when pass lands in `[90, 91]`
  - midpoint fail/pass refinement to avoid >10% overshoot
- VFR/rotation safety:
  - use `-fps_mode passthrough`
  - keep source stream as VMAF reference with decode-order alignment (`settb=AVTB,setpts=N`)
  - use CPU encoder for rotated sources (Display Matrix) to avoid HW QC drift
- Hardware flags must be capability-gated by encoder help output
  (NVENC and VideoToolbox options vary by ffmpeg build).
- VMAF requires `libvmaf` filter availability (not `vmafmotion`).

## Image Rules To Preserve

- JPEG quality search targets SSIMULACRA2 >= 85.
- Orientation normalization is explicit and deterministic
  (avoid double-rotation drift).
- Metadata verification stays strict for core capture/user metadata.
- If metadata verification behavior changes:
  - add failing test first in `tests/Unit/Images/Domain/StrictMetadataVerifierTest.php`
  - then update `src/Images/Domain/StrictMetadataVerifier.php` with brief rationale.
- Parallel mode applies to JPEG optimization only; ARW archival remains sequential.

## Testing + Validation

- Unit tests: `tests/Unit/`
- Smoke tests: `tests/Smoke/` (run via `just smoke`)
- Prefer `FsTestCase` for filesystem-dependent tests.
- Test doubles preference:
  1. local stubs
  2. `TestHandler` for logs
  3. Mockery when needed

After meaningful code changes:

- Run `just ci`.

Markdown-only changes:

- Run `just markdown` (not `just ci`).

If style fails:

```bash
just cbf
just ci
```

## Documentation Sync

When behavior/tooling/CLI semantics change, update docs in the same change:

- `README.md`
- `AGENTS.md` (this file)
- relevant docs under `docs/`

Keep docs aligned with actual code paths, option names, and thresholds.

## Adding A New CLI Command

1. Add command class in `src/{Context}/Ui/Cli/`.
2. Use `#[AsCommand(name: '...')]`.
3. Implement `__invoke(OutputInterface $output, ...): int`.
4. Use `CliHelper::startCommand()` prelude.
5. Wire dependencies and register in `app.php`.
