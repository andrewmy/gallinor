# Dockerization Plan (Toolchain-First)

## Summary

Standardize Gallinor’s external toolchain (FFmpeg, libheif tools, libavif
tools, ExifTool, SSIMULACRA2, xz/tar) by running the CLI inside a Docker image.

This is primarily about **shipping binaries reliably**. Parallelism remains an
**in-app worker pool** (`images:squeeze --parallel`) where the master spawns
child PHP worker processes inside the same container.

## Goals

- Make setup “works out of the box” by bundling required binaries.
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
  containers, so we need a Linux-capable platform implementation (or extend the
  existing one) for:
  - `Platform::nCores()` in containers
  - `Platform::findTool()` mappings (notably `ssimulacra2` binary name)

## Toolchain inventory (Docker image must include)

Images:

- `ffmpeg` (rotation normalization)
- `exiftool` (metadata copy + portrait/live photo detection)
- `ssimulacra2` (quality metric)
- `heif-enc` (HEIC encode; via libheif tools)
- `avifenc` / `avifdec` (if AVIF output remains supported; via libavif tools)

ARW archiving:

- `tar`
- `xz`

Videos (already part of the app; included here because Docker is the toolchain
strategy):

- `ffmpeg` with `libx265`
- optional: `hevc_nvenc` (NVIDIA hosts only)

## Phase 1 — Docker image (CPU-only baseline)

Deliverables:

- `Dockerfile` that builds a Linux image capable of running Gallinor end-to-end.
- A minimal `docker-compose.yml` that bind-mounts the gallery and runs commands.

Baseline approach:

- Start with a Debian/Ubuntu base image for widest package availability.
- Install PHP 8.5 (or a pinned PHP base image if available in your toolchain).
- Install the toolchain packages via `apt` where feasible.
- For tools not reliably available via packages (often `ssimulacra2`), download
  a pinned release artifact or build from source in a multi-stage build.

Acceptance criteria:

- Running:

  ```bash
  docker compose run --rm gallinor \
    php app.php images:squeeze --dry-run /data/gallery
  ```

  succeeds and tool detection works.

### Example: Dockerfile notes (toolchain)

Suggested base:

- `php:8.5-cli`

Suggested packages (names vary by distro):

- HEIC: `libheif-examples` (for `heif-enc` / `heif-convert`)
- Optional AVIF: `libavif-bin`
- Quality + metadata: `ffmpeg`, `libimage-exiftool-perl`
- Archiving: `xz-utils`, `tar`
- Build helpers (if needed): `git`, `unzip`, build toolchain

SSIMULACRA2:

- Either ship a pinned binary, or build/install `ssimulacra2_rs`.
- Confirm the Linux binary name (`ssimulacra2` vs `ssimulacra2_rs`) and map it
  in `Platform::findTool()`.

### Example: NVENC-capable FFmpeg (optional)

Relying on distro `ffmpeg` packages is inconsistent for NVENC (`hevc_nvenc`).
If you want predictable NVENC support, build FFmpeg from source in a multi-stage
Dockerfile with `nv-codec-headers` and `libx265`.

High-level outline:

- Build stage: compile FFmpeg with NVENC + `libx265`.
- Runtime stage: copy the built `ffmpeg` into the final image.
- NVENC runtime libraries come from the host driver (NVIDIA Container Toolkit /
  Docker Desktop GPU integration), not from the image.

Trimmed example:

```dockerfile
FROM php:8.5-cli AS ffmpeg-build

RUN apt-get update && apt-get install -y --no-install-recommends \
  ca-certificates git build-essential pkg-config yasm nasm \
  libx265-dev \
  && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 https://github.com/FFmpeg/nv-codec-headers.git /tmp/nv \
  && make -C /tmp/nv install

RUN git clone --depth 1 https://github.com/FFmpeg/FFmpeg.git /tmp/ffmpeg \
  && cd /tmp/ffmpeg \
  && ./configure --prefix=/opt/ffmpeg --disable-doc --disable-debug \
    --enable-gpl --enable-libx265 \
    --enable-nvenc --enable-nvdec --enable-cuvid \
  && make -j"$(nproc)" \
  && make install

FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
  libheif-examples \
  libavif-bin \
  ffmpeg libimage-exiftool-perl xz-utils tar \
  && rm -rf /var/lib/apt/lists/*

COPY --from=ffmpeg-build /opt/ffmpeg/ /usr/local/
```

### Example: docker-compose.yml (single-container execution)

Parallelism is internal, so Compose does not need a “worker” service.

```yaml
services:
  gallinor:
    build: .
    working_dir: /app
    volumes:
      - ${GALLINOR_GALLERY_PATH:-./gallery}:/data/gallery
```

#### Running with a custom gallery path

`/data/gallery` is the **container path**. The host path is provided via
`GALLINOR_GALLERY_PATH` (used by Compose for volume interpolation).

macOS/Linux:

```bash
GALLINOR_GALLERY_PATH="$HOME/Photos/vacation-2024" \
  docker compose run --rm gallinor \
  php app.php images:squeeze /data/gallery --dry-run
```

Windows PowerShell:

```powershell
$env:GALLINOR_GALLERY_PATH = "C:\\Users\\User\\Pictures\\vacation-2024"
docker compose run --rm gallinor `
  php app.php images:squeeze /data/gallery --dry-run
```

Windows CMD:

```bat
set GALLINOR_GALLERY_PATH=C:\Users\User\Pictures\vacation-2024
docker compose run --rm gallinor php app.php images:squeeze /data/gallery ^
  --dry-run
```

If you prefer not to use `GALLINOR_GALLERY_PATH`, you can also bypass Compose
and bind-mount directly:

```bash
docker run --rm -v "/host/path:/data/gallery" gallinor \
  php app.php images:squeeze /data/gallery --dry-run
```

## Phase 2 — Linux platform support in the app

Update `Platform` implementation to support Linux:

- `nCores()`:
  - Prefer `nproc` inside the container (respects cgroup limits on modern
    Docker/cgroup v2 setups).
- `findTool()`:
  - Use `which` like macOS.
  - Confirm the correct SSIMULACRA2 binary name in the image:
    - some environments use `ssimulacra2`, others `ssimulacra2_rs`.

Acceptance criteria:

- The CLI prints the correct core count and finds tools when run inside
  Docker.

## Phase 3 — Docker UX (wrapper scripts)

Add cross-platform helper scripts to make Docker the “default runner”
without users needing to remember compose incantations.

Requirements:

- Support gallery path selection with priority: CLI arg → env var → default.
- Provide a single “run” entrypoint for common commands:
  - `images:squeeze`, `images:remove-originals`, `videos:squeeze`, etc.
- Optionally auto-detect cores inside the container and pass `--concurrency`
  explicitly (until Linux `Platform::nCores()` is implemented, or as a belt and
  braces approach).

### Wrapper script behaviour (v1)

The wrapper scripts should:

- resolve gallery path (CLI arg → env var → default)
- optionally enable an NVIDIA compose override (`docker-compose.nvidia.yml`,
  best-effort with CPU fallback)
- probe container CPU count via `nproc` (optional)
- pass an explicit `--concurrency` when `--parallel` is used (optional)

### macOS/Linux: `scripts/docker-run.sh`

Usage (one-liner):

```bash
./scripts/docker-run.sh images:squeeze "$HOME/Photos/vacation-2024" --parallel
```

Disable NVIDIA probing/override explicitly:

```bash
./scripts/docker-run.sh --no-nvidia images:squeeze "$HOME/Photos/vacation-2024" --parallel
```

Draft:

```bash
#!/usr/bin/env bash
set -euo pipefail

USE_NVIDIA=1
if [[ "${1:-}" == "--no-nvidia" ]]; then
  USE_NVIDIA=0
  shift
fi
if [[ "${GALLINOR_DOCKER_GPU:-}" == "none" ]]; then
  USE_NVIDIA=0
fi

COMMAND="${1:-}"
if [[ -z "$COMMAND" ]]; then
  echo "Usage: $0 [--no-nvidia] <command> [gallery_path] [args...]" >&2
  exit 2
fi
shift

GALLERY_PATH="${1:-}"
if [[ -n "${GALLERY_PATH}" && "${GALLERY_PATH}" != -* ]]; then
  shift
else
  GALLERY_PATH="${GALLINOR_GALLERY_PATH:-./gallery}"
fi

export GALLINOR_GALLERY_PATH="$GALLERY_PATH"

BASE_COMPOSE=(-f docker-compose.yml)
GPU_COMPOSE=("${BASE_COMPOSE[@]}")
if [[ "$USE_NVIDIA" -eq 1 && -f docker-compose.nvidia.yml ]]; then
  GPU_COMPOSE+=(-f docker-compose.nvidia.yml)
fi

WANTS_PARALLEL=0
HAS_CONCURRENCY=0
for arg in "$@"; do
  [[ "$arg" == "--parallel" ]] && WANTS_PARALLEL=1
  [[ "$arg" == "--concurrency" || "$arg" == --concurrency=* ]] && HAS_CONCURRENCY=1
done

EXTRA_ARGS=()
if [[ "$WANTS_PARALLEL" -eq 1 && "$HAS_CONCURRENCY" -eq 0 ]]; then
  N_CORES="$(
    docker compose "${BASE_COMPOSE[@]}" run --rm --no-deps --build gallinor \
      sh -lc 'nproc 2>/dev/null || getconf _NPROCESSORS_ONLN' 2>/dev/null \
      | tr -d '\r\n' || echo 1
  )"
  N_CORES="${N_CORES:-1}"

  A=$((N_CORES / 4))
  B=$((N_CORES / 8 + 2))
  CONCURRENCY=$A
  if ((B < A)); then CONCURRENCY=$B; fi
  if ((CONCURRENCY < 1)); then CONCURRENCY=1; fi

  EXTRA_ARGS=(--concurrency="$CONCURRENCY")
fi

RUN_ARGS=(run --rm --build gallinor php app.php "$COMMAND" /data/gallery)
RUN_ARGS+=("${EXTRA_ARGS[@]}")
RUN_ARGS+=("$@")

if ! docker compose "${GPU_COMPOSE[@]}" "${RUN_ARGS[@]}"; then
  if [[ "$USE_NVIDIA" -eq 1 && -f docker-compose.nvidia.yml ]]; then
    echo "NVIDIA override failed; retrying CPU-only." >&2
    docker compose "${BASE_COMPOSE[@]}" "${RUN_ARGS[@]}"
  else
    exit 1
  fi
fi
```

### Windows: `scripts/docker-run.ps1`

Usage (one-liner):

```powershell
.\scripts\docker-run.ps1 images:squeeze "C:\Users\User\Pictures\vacation-2024" --parallel
```

Disable NVIDIA probing/override explicitly:

```powershell
.\scripts\docker-run.ps1 --no-nvidia images:squeeze "C:\Users\User\Pictures\vacation-2024" --parallel
```

Draft:

```powershell
param(
  [Parameter(ValueFromRemainingArguments = $true)]
  [string[]] $argv
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$useNvidia = $true
if ($argv.Count -gt 0 -and $argv[0] -eq "--no-nvidia") {
  $useNvidia = $false
  $argv = if ($argv.Count -gt 1) { $argv[1..($argv.Count - 1)] } else { @() }
}
if ($env:GALLINOR_DOCKER_GPU -eq "none") {
  $useNvidia = $false
}

if ($argv.Count -lt 1) {
  Write-Error "Usage: .\scripts\docker-run.ps1 [--no-nvidia] <command> [gallery_path] [args...]"
}

$command = $argv[0]
$rest = @()
if ($argv.Count -gt 1) { $rest = $argv[1..($argv.Count - 1)] }

# Gallery path is optional. If the first remaining arg does not start with '-',
# treat it as the host path to mount.
$galleryPath = $env:GALLINOR_GALLERY_PATH
if (-not $galleryPath) { $galleryPath = ".\gallery" }

if ($rest.Count -gt 0 -and -not $rest[0].StartsWith("-")) {
  $galleryPath = $rest[0]
  $rest = if ($rest.Count -gt 1) { $rest[1..($rest.Count - 1)] } else { @() }
}

$env:GALLINOR_GALLERY_PATH = $galleryPath

$baseCompose = @("-f", "docker-compose.yml")
$gpuCompose = @($baseCompose)
if ($useNvidia -and (Test-Path "docker-compose.nvidia.yml")) {
  $gpuCompose += @("-f", "docker-compose.nvidia.yml")
}

$wantsParallel = $rest -contains "--parallel"
$hasConcurrency = $false
for ($i = 0; $i -lt $rest.Count; $i++) {
  if ($rest[$i] -eq "--concurrency") { $hasConcurrency = $true }
  if ($rest[$i] -like "--concurrency=*") { $hasConcurrency = $true }
}

$extraArgs = @()
if ($wantsParallel -and -not $hasConcurrency) {
  $nCores = 1
  try {
    $nCoresRaw = & docker compose @baseCompose run --rm --no-deps --build gallinor `
      sh -lc 'nproc 2>/dev/null || getconf _NPROCESSORS_ONLN' 2>$null
    [void][int]::TryParse(($nCoresRaw | Out-String).Trim(), [ref]$nCores)
  } catch {
    $nCores = 1
  }

  $a = [int]($nCores / 4)
  $b = [int]($nCores / 8) + 2
  $concurrency = [Math]::Max(1, [Math]::Min($a, $b))
  $extraArgs = @("--concurrency=$concurrency")
}

$runArgs = @("run", "--rm", "--build", "gallinor", "php", "app.php", $command, "/data/gallery")
$runArgs += $extraArgs
$runArgs += $rest

try {
  & docker compose @gpuCompose @runArgs
} catch {
  if ($useNvidia -and (Test-Path "docker-compose.nvidia.yml")) {
    Write-Warning "NVIDIA override failed; retrying CPU-only."
    & docker compose @baseCompose @runArgs
  } else {
    throw
  }
}
```

## Phase 4 — GPU notes (videos)

### macOS: no VideoToolbox inside Docker

`videotoolbox` is a macOS framework. Docker on macOS runs Linux containers in a
VM, so FFmpeg in the container cannot access VideoToolbox.

Implication:

- Docker runs on macOS will use CPU encoding (`libx265`), not VideoToolbox.

### Windows: NVIDIA NVENC via Docker Desktop (WSL2)

Host requirements:

- Docker Desktop with WSL2 backend
- NVIDIA GPU + drivers with WSL2 GPU support

Validation command:

```bash
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi
```

### Enable GPU for Gallinor containers

Keep GPU config in an optional override file, e.g. `docker-compose.nvidia.yml`,
so CPU-only hosts can still use base compose.

Inside the built image, confirm FFmpeg supports NVENC:

```bash
docker compose run --rm gallinor \
  ffmpeg -hide_banner -encoders | grep -i nvenc
```

If `hevc_nvenc` is missing, build/install an NVENC-enabled FFmpeg (often
requires a multi-stage build and `nv-codec-headers`).

## Future (optional) — Multi-container distribution

If we later need to distribute work across multiple containers/machines, then a
durable queue (Messenger/SQLite/Redis/etc.) becomes relevant. This is not
required for the “Dockerize the toolchain” goal and should stay separate
from the initial Docker rollout.
