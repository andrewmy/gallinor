# Dockerization Plan (Toolchain-First)

## Summary

Standardize Gallinor's external toolchain (FFmpeg, libheif tools, ExifTool,
SSIMULACRA2, xz/tar) by running the CLI inside a Docker image.

This is primarily about **shipping binaries reliably**. Parallelism remains an
**in-app worker pool** (`images:squeeze --parallel`) where the master spawns
child PHP worker processes inside the same container.

## Related plans

- [README.md](README.md) (index)
- [QUALITY_SEARCH_UNIFICATION_PLAN.md](QUALITY_SEARCH_UNIFICATION_PLAN.md)
  (quality/bitrate probing + refinement)

## Goals

- Make setup "works out of the box" by bundling required binaries.
- Pin tool versions for reproducible results and fewer platform edge cases.
- Keep host mode supported (macOS/Windows), but enable Docker to become the
  default execution environment.
- Preserve current CLI UX; Docker should be an execution wrapper, not a new API.
- Enable optional NVIDIA NVENC acceleration for video work on supported hosts.

## Non-goals (v1)

- Distributed work across multiple containers/machines.
- Queue-backed durable resume (SQLite/Messenger/Redis/etc.).
- Apple VideoToolbox support in Docker (not possible; see notes below).

## Current gaps to address

- `NativePlatform` currently supports only macOS and Windows. Docker runs Linux
  containers, so Linux support must be added:
  - `Platform::nCores()` — add `nproc` for Linux (respects cgroup limits).
  - `Platform::findTool()` — `which` already works, no change needed.
  - `Platform::isDarwin()` — new method to gate VideoToolbox probe (currently
    gated on `!isWindows()`, which wastes a probe on Linux).
  - `RawArchiver` — uses bare `tar` command without `findTool()`, unlike
    `ArchiveVerifier`. Fix the inconsistency.

## Toolchain inventory (Docker image must include)

Images:

- `ffmpeg` (rotation normalization)
- `ffprobe` (video stream metadata: dimensions, bitrate, codec, pixel format)
- `exiftool` (metadata copy + portrait/live photo detection)
- `ssimulacra2` (image quality metric)
- `heif-enc` (HEIC encode; via libheif tools)
- `heif-dec` (HEIC decode back to PNG for quality comparison)

ARW archiving:

- `tar`
- `xz`

Videos:

- `ffmpeg` with `libx265` (CPU encoding)
- `ffmpeg` with `libvmaf` (**required** — video quality scoring; `FfmpegEncoder`
  throws `RuntimeException` if the `libvmaf` filter is missing)
- optional: `hevc_nvenc` (NVIDIA hosts only)

## Toolchain sources

| Tool | Source | Notes |
| --- | --- | --- |
| **PHP 8.5** | `php:8.5-cli-bookworm` base image | Stable since Nov 2025 |
| **ffmpeg + ffprobe** | `COPY --from=mwader/static-ffmpeg:8.0.1` | Static binaries, includes libvmaf, libx265, all codecs. No build stage needed. |
| **heif-enc + heif-dec** | `apt -t bookworm-backports libheif-examples libheif-plugin-x265` | Backports required: stable 1.15.1 lacks `heif-dec` and uses built-in x265; backports 1.19.7 has `heif-dec` but uses plugin architecture requiring separate `libheif-plugin-x265`. |
| **ssimulacra2** | Build from [cloudinary/ssimulacra2](https://github.com/cloudinary/ssimulacra2) in multi-stage | Binary name: `ssimulacra2`. Syntax: `ssimulacra2 <ref> <cand>`. Matches existing Unix code path — no `image` subcommand. |
| **exiftool** | `apt install libimage-exiftool-perl` | Standard package |
| **tar** | Pre-installed in Debian | Already in base image |
| **xz** | `apt install xz-utils` | Standard package |

### Why not `ssimulacra2_rs` (Rust)?

`cargo install ssimulacra2_rs` produces a binary named `ssimulacra2_rs` with
syntax `ssimulacra2_rs image <ref> <cand>` (requires `image` subcommand). The
existing code only uses the `image` subcommand on Windows. Using the Rust binary
on Linux would require either renaming + code changes, or a symlink + wrong CLI
syntax. The Cloudinary C++ build avoids all of this.

### Why not `apt install ffmpeg`?

Debian's packaged FFmpeg does **not** include `libvmaf`. Without it,
`videos:squeeze` fails at startup (`FfmpegEncoder` checks for the `libvmaf`
filter and throws). `mwader/static-ffmpeg` includes libvmaf, libx265, and
every other codec needed, as fully static binaries with zero dependencies.

## Phase 1 — Docker image (CPU-only baseline)

Deliverables:

- `Dockerfile` — multi-stage build producing a Linux image with the full toolchain.
- `docker-compose.yml` — base compose for CPU usage.
- `docker-compose.nvidia.yml` — GPU overlay (layered with `-f`).
- `docker-compose.override.yml.dist` — development template (source mount).
- `.dockerignore` — exclude build artifacts from context.
- `justfile` + CI workflow update — `just docker-smoke` target for containerized
  toolchain checks.

### Dockerfile

Base image: `php:8.5-cli-bookworm`.

```dockerfile
# syntax=docker/dockerfile:1

# --- Stage 1: build ssimulacra2 ---
FROM debian:bookworm-slim AS ssimulacra2-build

RUN apt-get update && apt-get install -y --no-install-recommends \
    cmake ninja-build g++ git ca-certificates \
    libhwy-dev liblcms2-dev libjpeg62-turbo-dev libpng-dev \
  && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://github.com/cloudinary/ssimulacra2.git /src \
  && cmake -S /src -B /build -DCMAKE_BUILD_TYPE=Release -G Ninja \
  && ninja -C /build

# --- Stage 2: runtime ---
FROM php:8.5-cli-bookworm

# Prevent docs/man/locale from being installed
RUN echo 'path-exclude=/usr/share/doc/*' > /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/man/*' >> /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/locale/*' >> /etc/dpkg/dpkg.cfg.d/nodoc \
  && echo 'path-exclude=/usr/share/info/*' >> /etc/dpkg/dpkg.cfg.d/nodoc

# Enable bookworm-backports for libheif 1.19+ (heif-dec + x265 plugin)
# BuildKit cache mounts keep apt cache across builds without bloating the image
RUN echo 'deb http://deb.debian.org/debian bookworm-backports main' \
      > /etc/apt/sources.list.d/backports.list
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
      libimage-exiftool-perl \
      xz-utils \
    && apt-get install -y --no-install-recommends -t bookworm-backports \
      libheif-examples \
      libheif-plugin-x265

# Remove docs/locale that were already present in the base image
RUN rm -rf /usr/share/doc/* /usr/share/man/* /usr/share/locale/* /usr/share/info/*

# Static ffmpeg + ffprobe (includes libvmaf, libx265, all codecs)
COPY --from=mwader/static-ffmpeg:8.0.1 /ffmpeg /usr/local/bin/
COPY --from=mwader/static-ffmpeg:8.0.1 /ffprobe /usr/local/bin/

# ssimulacra2 from build stage
COPY --from=ssimulacra2-build /build/ssimulacra2 /usr/local/bin/

# App installation
WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    --mount=from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist

COPY . .
RUN --mount=from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --optimize
RUN mkdir -p var && chmod 777 var

ENTRYPOINT ["php", "app.php"]
```

### .dockerignore

```text
.git/
vendor/
var/
gallery/
node_modules/
*.md
docs/
tests/
.phpunit*
phpstan*
rector*
```

### docker-compose.yml

```yaml
services:
  gallinor:
    build: .
    volumes:
      - ${GALLINOR_GALLERY_PATH:-./gallery}:/data/gallery
    tmpfs:
      - /tmp:size=4G
```

The `tmpfs` mount for `/tmp` avoids disk I/O for temp files (VMAF JSON logs,
intermediate video encodes, parallel worker temp dirs under
`sys_get_temp_dir()/gallinor-parallel/`).

### docker-compose.nvidia.yml

Separate overlay file for GPU support. Uses the same `gallinor` service name.

```yaml
services:
  gallinor:
    gpus: all
```

- CPU: `docker compose run --rm gallinor ...`
- GPU: `docker compose -f docker-compose.yml -f docker-compose.nvidia.yml \
  run --rm gallinor ...`

Compatibility note:

- Compose has two GPU syntaxes:
  - `deploy.resources.reservations.devices` (Deploy spec; rich fields like
    `driver`, `device_ids`, `count`)
  - `gpus` service field (short form; requires newer Compose)
- Plan choice: use `gpus: all` for local `docker compose` UX and set minimum
  requirement to Docker Compose `2.30.0+`.
- Wrappers/scripts must preflight and fail fast if the requirement is not met.

### docker-compose.override.yml.dist (development)

Template for development (source mounted instead of baked in):

```yaml
services:
  gallinor:
    volumes:
      - .:/app
      - ${GALLINOR_GALLERY_PATH:-./gallery}:/data/gallery
    tmpfs:
      - /tmp:size=4G
```

Usage: `cp docker-compose.override.yml.dist docker-compose.override.yml`

Docker Compose auto-loads `docker-compose.override.yml` when present, so the dev
mount activates without extra flags. Add `docker-compose.override.yml` to
`.gitignore`.

### CPU allocation

Containers use all CPUs the Docker engine sees — no limits in compose. On native
Linux this is all host CPUs. On Docker Desktop (macOS/Windows), the VM has a
fixed CPU count set in **Settings > Resources**. There is no compose-level or CLI
way to request more CPUs than the VM has — users must increase this in Docker
Desktop settings for best parallel performance.

### Bind-mount ownership (non-root outputs)

The image runs as root by default. Without user mapping, optimized files written
to host bind mounts can become root-owned.

Recommended policy:

- macOS/Linux wrappers pass `--user "$(id -u):$(id -g)"` to `docker compose run`.
- Windows wrapper keeps default container user unless there is a tested
  alternative in WSL2 environments.
- Keep this behavior explicit in both wrapper scripts and README usage examples.

### Running with a custom gallery path

`/data/gallery` is the **container path**. The host path is provided via
`GALLINOR_GALLERY_PATH` (Compose volume interpolation).

macOS/Linux:

```bash
GALLINOR_GALLERY_PATH="$HOME/Photos/vacation-2024" \
  docker compose run --rm gallinor \
  images:squeeze /data/gallery --dry-run
```

Windows PowerShell:

```powershell
$env:GALLINOR_GALLERY_PATH = "C:\\Users\\User\\Pictures\\vacation-2024"
docker compose run --rm gallinor `
  images:squeeze /data/gallery --dry-run
```

Or bypass Compose and bind-mount directly:

```bash
docker run --rm -v "/host/path:/data/gallery" gallinor \
  images:squeeze /data/gallery --dry-run
```

### Acceptance criteria

```bash
# Tool detection — images
docker compose run --rm gallinor images:squeeze --dry-run /data/gallery

# Tool detection — videos (CPU mode)
docker compose run --rm gallinor videos:squeeze --dry-run --use-cpu /data/gallery

# Verify specific tools
docker compose run --rm --entrypoint sh gallinor -c \
  'command -v ffmpeg ffprobe heif-enc heif-dec exiftool ssimulacra2 xz tar'
docker compose run --rm --entrypoint sh gallinor -c \
  'ffmpeg -filters 2>&1 | grep libvmaf'
docker compose run --rm --entrypoint sh gallinor -c \
  'heif-enc --list-encoders 2>&1 | grep x265'
```

### Automated smoke checks

- Add `just docker-smoke` target to build the image and run the Phase 1
  acceptance checks above.
- Add a CI job that runs `just docker-smoke` on pushes/PRs so Docker regressions
  fail before release.

### Image size

Estimated ~320 MB compressed. The two largest components (PHP base ~181 MB,
static FFmpeg ~118 MB) make up 92% of the total. The Dockerfile includes
low-effort optimizations:

- `path-exclude` in dpkg config prevents docs/man/locale from being installed
- Cleanup of pre-existing docs/locale from the base image (~5–10 MB saved)
- BuildKit cache mounts for apt and composer (faster rebuilds, no size bloat)
- Composer binary bind-mounted at build time instead of copied into the image

Further size reductions would require Alpine (uncertain libheif x265 plugin
availability) or a custom minimal FFmpeg build (high maintenance cost). Neither
is worth the complexity at this image size.

## Phase 2 — Linux platform support in the app

### 2a. Add Linux to NativePlatform

**File:** `src/Shared/Infrastructure/NativePlatform.php`

- Add `OS_LINUX = 'Linux'` constant and add it to the constructor whitelist.
- Add `isLinux(): bool` and `isDarwin(): bool` to the `Platform` interface
  (`src/Shared/Domain/Platform.php`) and implementation.
- Add Linux branch in `detectNCores()`: use `nproc` (respects cgroup limits).
- `findTool()`: `which` already works for Linux — no change needed.

### 2b. Fix VideoToolbox probe on Linux

**File:** `src/Video/Infrastructure/FfmpegEncoder.php`

Change the VideoToolbox gate from `!isWindows()` to `isDarwin()`:

```php
// Before:
$hasAppleToolbox = !$this->platform->isWindows()
    && $this->ffmpegHasEncoder('hevc_videotoolbox');

// After:
$hasAppleToolbox = $this->platform->isDarwin()
    && $this->ffmpegHasEncoder('hevc_videotoolbox');
```

This avoids a wasted encoder probe on Linux.

### 2c. Fix tar inconsistency in RawArchiver

**File:** `src/Images/Domain/RawArchiver.php`

`RawArchiver` invokes `tar` as a bare command without `findTool()`, while
`ArchiveVerifier` uses `findTool('tar')`. Fix by adding
`$this->tarPath = $this->platform->findTool('tar')` to the constructor and using
the resolved path in both `archiveWindows()` and `archiveUnix()`.

### 2d. OS-gated code audit

| Location | Current gate | Linux behavior |
| --- | --- | --- |
| `NativePlatform::__construct` | Whitelist Darwin/Windows | Add Linux |
| `NativePlatform::detectNCores` | Darwin/Windows branches | Add `nproc` branch |
| `NativePlatform::findTool` | `isWindows()` for `where.exe` vs `which` | OK — falls to `which` |
| `NativePlatform::findTool` | `isWindows()` for ssimulacra2 rename | OK — Linux uses `ssimulacra2` name |
| `Ssimulacra2::score` | `isWindows()` for `image` subcommand | OK — Linux uses `[$path, $ref, $cand]` matching Cloudinary binary |
| `FfmpegEncoder::__construct` | `!isWindows()` for VideoToolbox | Fixed: use `isDarwin()` |
| `Exiftool::command` | `isWindows()` for UTF-8 charset flag | OK — skipped on Linux |
| `Exiftool::normalizePathArgument` | `isWindows()` for backslash/realpath | OK — skipped on Linux |
| `RawArchiver::archive` | `isWindows()` for two-step tar | OK — uses Unix pipe path |
| `RawArchiver::archive` | bare `tar` command | Fixed: use `findTool('tar')` |

### Phase 2 acceptance criteria

- The CLI prints the correct core count and finds all tools when run inside Docker.
- `images:squeeze --dry-run` and `videos:squeeze --dry-run --use-cpu` both succeed.

## Phase 3 — Docker UX (wrapper scripts)

Add cross-platform helper scripts to make Docker the "default runner"
without users needing to remember compose incantations.

### Design decisions

- **No `nproc` probing** in wrapper scripts. After Phase 2, `NativePlatform`
  handles `nCores()` on Linux via `nproc`, and `ParallelConcurrency::defaultFromCores()`
  sets smart defaults. No need to duplicate this logic in shell.
- **No `--build` on every run**. Users run `docker compose build` explicitly.
- **GPU via `-f` overlay**. The `--nvidia` flag layers `docker-compose.nvidia.yml`
  automatically.
- **Fail fast on GPU requests**. If `--nvidia` prerequisites are missing, wrappers
  exit with a clear error; no automatic CPU fallback.

### macOS/Linux: `scripts/docker-run.sh`

```bash
./scripts/docker-run.sh images:squeeze "$HOME/Photos/vacation-2024" --parallel
./scripts/docker-run.sh --nvidia videos:squeeze "$HOME/Videos"
```

Behavior:

- Resolve gallery path: CLI arg > `$GALLINOR_GALLERY_PATH` > `./gallery`
- Optional `--nvidia` flag: layer `docker-compose.nvidia.yml` via `-f`
- Preflight checks for `--nvidia` (Compose version + `nvidia-smi` container test)
- Fail fast when `--nvidia` preflight fails
- Pass remaining args through to the app

### Windows: `scripts/docker-run.ps1`

Same logic in PowerShell.

## Phase 4 — GPU notes (videos)

### macOS: no VideoToolbox inside Docker

Docker on macOS runs Linux containers in a VM — FFmpeg cannot access
VideoToolbox. Docker runs will use CPU encoding (`libx265`).

### Windows/Linux: NVIDIA NVENC

Host requirements:

- Docker Desktop with WSL2 backend (Windows), or native Docker (Linux)
- NVIDIA GPU + drivers with container toolkit support
- Docker Compose `2.30.0+` (required for `gpus` service field)

Preflight (wrappers must run this before any `--nvidia` Gallinor command):

```bash
docker compose version
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi
```

If either check fails, wrapper exits non-zero with remediation text. Do not
fallback to CPU mode automatically.

Validation:

```bash
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi
```

Confirm FFmpeg supports NVENC inside the image:

```bash
docker compose -f docker-compose.yml -f docker-compose.nvidia.yml run --rm gallinor \
  sh -c 'ffmpeg -hide_banner -encoders 2>&1 | grep -i nvenc'
```

The static FFmpeg from `mwader/static-ffmpeg` includes NVENC codec support.
NVENC runtime libraries come from the host driver via the NVIDIA Container
Toolkit, not from the image.

## Future (optional) — Multi-container distribution

If we later need to distribute work across multiple containers/machines, then a
durable queue (Messenger/SQLite/Redis/etc.) becomes relevant. This is not
required for the "Dockerize the toolchain" goal and should stay separate
from the initial Docker rollout.
