# Gallinor

This is a CLI tool for reducing the size of your video and image gallery while
maintaining quality. It supports almost no customization, focusing instead on
simplicity and ease of use.

## Features

- Support for macOS, Windows, and Linux (via Docker)
- Video:
  - Reduce mp4 video file sizes, re-encoding everything to HEVC (H.265) with
    quality-based bitrate (VMAF score ≥ 90)
  - Support 720p, 1080p, and 4K videos
  - Support for Apple and NVidia hardware acceleration for video encoding
  - Support for CPU video encoding as a last resort
- Images:
  - Convert JPEGs to HEIC (default) with quality-based encoding (SSIMULACRA2
    score ≥ 85)
  - Archive ARW raw files with xz compression (~30% reduction)
  - Skip photos with no size benefit from conversion
  - Skip Samsung Portrait Mode and Live Photos (not sure about iOS)

## Quick start (Docker)

Docker bundles the entire toolchain (ffmpeg, libheif, ssimulacra2, exiftool,
xz) so nothing else needs to be installed.

```shell
docker compose build
./bin/docker-run.sh images:squeeze "$HOME/Photos" --dry-run
```

The wrapper script mounts each host directory into the container automatically
and maps file ownership so outputs aren't root-owned:

```shell
./bin/docker-run.sh images:squeeze "$HOME/Photos" --parallel
./bin/docker-run.sh videos:squeeze "$HOME/Videos" --use-cpu --dry-run
./bin/docker-run.sh images:squeeze "$HOME/Photos/2024" "$HOME/Photos/2025"
```

PowerShell:

```powershell
.\bin\docker-run.ps1 images:squeeze "$HOME\Photos" --parallel
```

Without the wrapper, set `GALLINOR_GALLERY_PATH` and use `/data/gallery` as
the container path:

```shell
GALLINOR_GALLERY_PATH="$HOME/Photos" docker compose run --rm gallinor \
  images:squeeze /data/gallery --parallel
```

### NVIDIA GPU acceleration

For video encoding with NVENC, pass `--nvidia` to the wrapper:

```shell
./bin/docker-run.sh --nvidia videos:squeeze "$HOME/Videos"
```

Requirements: NVIDIA GPU + drivers, NVIDIA Container Toolkit, Docker Compose
2.30.0+. The wrapper runs preflight checks and fails with clear errors if
anything is missing.

Apple VideoToolbox is not available inside Docker (the container runs Linux).
On macOS, Docker video runs will use CPU encoding (`--use-cpu`).

### Docker Desktop CPU note

The container uses all CPUs the Docker engine sees. On Docker Desktop
(macOS/Windows), this is limited to the VM's CPU count in
**Settings > Resources** — increase it there for better parallel performance.

## Native setup

For running without Docker on macOS or Windows.

### Requirements

- PHP 8.5 or higher
- Composer
- On Windows:
  - PowerShell
- For video:
  - FFmpeg v8+ with HEVC (H.265) support installed and available in your
    system PATH
    - macOS: `brew install ffmpeg`
  - For hardware acceleration:
    - macOS: Apple Silicon or Intel with VideoToolbox support
    - Win: NVidia GPU with NVENC support
  - For quality check — VMAF library installed and available in your system
    PATH, usually is in the box with ffmpeg
- For images:
  - ffmpeg v8+ (used for rotation-safe quality comparisons)
    - macOS: `brew install ffmpeg`
  - libheif (HEIC tools)
    - macOS: `brew install libheif`
    - Win: <https://github.com/pphh77/libheif-Windowsbinary/releases>
  - ssimulacra2
    - Rust
      - macOS: `brew install rust`
      - Win:
        - `winget install Rustlang.Rustup`
        - <https://visualstudio.microsoft.com/downloads/#build-tools-for-visual-studio-2026>
          — choose "Desktop C++" there too:
          <https://rust-lang.github.io/rustup/installation/windows-msvc.html>
    - `cargo install ssimulacra2_rs --no-default-features`
  - exiftool
    - macOS: `brew install exiftool`
    - Win: `winget install OliverBetz.ExifTool`
  - For raws — xz
    - macOS: `brew install xz`

### Installation

```shell
composer install
```

## Usage

For the full list of options and their descriptions:

```shell
php app.php help videos:squeeze
php app.php help images:squeeze
```

### Crush some vids

```shell
 php app.php videos:squeeze /path/to/videos [/path2 /path3 ...] [--dry-run] [--force-any-bitrate]
```

The result files are saved along the originals with the `.optimal.mp4` suffix.
When a first-pass encode lands with high quality headroom (VMAF >= 96), Gallinor
also probes lower bitrates in resolution-sized steps and keeps the smallest
variant that still passes VMAF >= 90.
By default, files with existing `.optimal.mp4` variants are skipped.
By default, videos whose bitrate is already acceptable are also skipped in
preflight. Use `--force-any-bitrate` to still run squeeze attempts for those
files.

### Rename optimal videos to replace originals

```shell
php app.php videos:rename /path/to/videos [/path2 /path3 ...] [--dry-run]
```

After checking the quality, finish the job here.

### Process images

```shell
php app.php images:squeeze /path/to/photos [/path2 /path3 ...] [--dry-run] [--parallel] [--concurrency=N | --adaptive-concurrency=N] [--worker-max-jobs=N] [--job-timeout=SECONDS]
```

Converts JPEGs to HEIC by default (saving alongside originals as `.heic`) and
archives ARW files per directory as `raws-N.tar.xz`.
HEIC encode/decode subprocesses run without Symfony's default 60s timeout to
avoid false failures on large frames.
JPEG orientation normalization is deterministic: Gallinor disables FFmpeg
autorotate and applies EXIF-orientation transform explicitly before quality
checks, then forces `Orientation=1` on outputs.

Parallel mode is optional and applies only to JPEG optimisation. ARW archival
remains sequential. `--job-timeout` is an inactivity timeout (no worker message
for that job). Use `-v`/`-vv`/`-vvv` to print worker-pool lifecycle and
dispatch tracing while the progress bar is running. At `-vv` and above, Gallinor
also shows a live per-worker status panel under the progress bar (when running
in an ANSI/TTY console; otherwise it falls back to plain trace lines).
Parallel worker policy:

- no `--concurrency` and no `--adaptive-concurrency`: fixed safe worker count
  from `ParallelConcurrency::defaultFromCores()`
- `--concurrency=N`: fixed `N` workers
- `--adaptive-concurrency=N`: start from safe workers and ramp up to max `N`
  while throughput gains remain meaningful (current gain threshold is 3% per
  scaling window)

In panel mode, trace updates are coalesced with worker-status refreshes to
avoid trace-only redraw spam.

Worker status phase map (panel/trace view):

- `prepare` -> setup/normalization before probe loop
- `encode` -> encode candidate image at current quality
- `decode` -> decode candidate for quality comparison
- `score` -> SSIMULACRA2 measurement (`q`, `score`, `Δ` vs original)
- `decision` -> probe decision (`pass`, `score_low`, `too_big`,
  `quality_not_achieved`)
- `finalize` -> move final optimized file to target path
- `metadata` -> copy + verify metadata on final output

### Metadata verification failures

Gallinor verifies metadata after image conversion. If you see
`Metadata verification failed`, check the listed tags:

- Usually non-portable across container rewrite (often safe to ignore in future
  releases): `System:*`, `QuickTime:*`, `JFIF:*`, `JSON:*`, vendor XMP blocks (for
  example `XMP-MiCamera:*`, `XMP-alienexposure:*`, `XMP-xmpNote:*`),
  maker-note projection tags
  (`MakerUnknown:Unknown_0xNNNN`, `ExifIFD:MakerNoteUnknown*`), Adobe Camera
  Raw local-correction/retouch tags (`XMP-crs:MaskGroupBasedCorr*`,
  `XMP-crs:RetouchArea*`, `XMP-crs:LookParameters*`), Photoshop
  camera-profile vignette tags
  (`XMP-photoshop:CameraProfilesPerspectiveModelVignetteModel*`), XMP EXIF
  projection tag (`XMP-exif:ExposureCompensation`), and private EXIF tags like
  `ExifIFD:Exif_0xNNNN`. JPEG other-image pointer tags (`IFD0:OtherImage*`)
  are also treated as non-portable. Dimension projection tags
  (`ExifIFD:ImageWidth`, `ExifIFD:ImageHeight`, `XMP-tiff:ImageWidth`,
  `XMP-tiff:ImageHeight`) and derived brightness metric (`ExifIFD:BrightnessValue`)
  are also treated as non-portable. Samsung trailer/vendor tags (`Samsung:*`)
  are treated as non-portable as well.
- `ExifIFD:ShutterSpeedValue` can be rewritten with different numeric
  representation when `ExifIFD:ExposureTime` is preserved; Gallinor treats this
  pair as equivalent capture metadata.
- `GPS:GPSAltitude` and `GPS:GPSHPositioningError` remain strict, but Gallinor
  tolerates equivalent numeric rewrite noise (units/rounding format only,
  `<= 0.01 m` difference).
- If source GPS is placeholder-only (for example empty/`Unknown ()`/`undef`),
  Gallinor treats it as missing-at-source and won't fail destination for absent
  core GPS tags.
- For missing `ExifIFD:ColorSpace`, Gallinor accepts equivalent `*:ColorSpace`
  projection when value is unchanged.
- Potentially important and should stay strict: camera/exposure/date/GPS/lens
  metadata (`EXIF:*`, `DateTime*`, GPS, make/model).

Gallinor also performs a post-rewrite repair pass for critical capture fields
(`GPSLatitude*`, `GPSLongitude*`, `GPSAltitude`, `ExifIFD:ColorSpace`). If
these still appear as missing, treat it as a real metadata-write problem.
When source GPS is present, Gallinor also writes HEIC-friendly GPS projection
tags (`QuickTime/Keys/ItemList/UserData:GPSCoordinates` and XMP GPS tags).

If the failure includes important tags, stop and report it with the full tag
list. If it only includes non-portable tags, updating to a newer Gallinor build
may already include verifier compatibility rules.

If an ExifTool write step fails (for example while forcing `Orientation=1` on
HEIC), Gallinor now retries once for transient temp-file write/rename errors
and surfaces ExifTool stderr directly in the CLI error message. ExifTool
read/write metadata operations also use minor-error mode (`-m`) to tolerate
non-critical parser warnings in vendor blocks.

## Development

### Native (requires local PHP + toolchain)

```shell
composer install
just ci
just smoke
```

`just ci` runs lint, static analysis, tests, and coverage. `just smoke` runs
environment-dependent smoke tests (real CLI + toolchain + worker IPC).
`just markdown` lints Markdown (requires Node/npx, not included in `ci`).

### Docker (no local PHP needed)

One-time setup:

```shell
docker compose build
cp docker-compose.override.yml.dist docker-compose.override.yml
docker compose run --rm --entrypoint sh gallinor -c 'composer install'
```

The override bind-mounts the repo into the container so code changes are
reflected immediately without rebuilding. The `composer install` populates
`vendor/` on the host through the mount.

All PHP-based Justfile targets work in Docker via `DOCKER=1`:

```shell
DOCKER=1 just test
DOCKER=1 just stan
DOCKER=1 just ci
```

Verify the Docker image toolchain:

```shell
just docker-smoke
```

## Design docs

- [docs/README.md](docs/README.md)

## Notes

### Video

- NVENC seems to achieve better visual quality with smaller bitrate. On a
  selection of complex 1080p videos with source bitrate 16 Mbps, to achieve VMAF
  score 90+:
  - Apple VideoToolbox needed 12-14 Mbps or fails completely;
  - NVENC needed 8-12 Mbps.
- The CPU encoder is very slow and its CRF rate is not really well tested, wear
  a hard hat and fire up some movie while using it. NVENC can still do
  something funny to rotated videos, so rotated clips fall back to CPU only
  when NVENC is the active encoder. On macOS, rotated clips can use Apple
  VideoToolbox.

### Photo

- DNG files seem to be compressed well, `xz -9 -T0` gives ~2.5%, not worth it.
- While videos appear to degrade significantly with VMAF score below 90, photos
  seem to be perfectly fine with SSIMULACRA2 score 85.
- Unfortunately there is no GPU acceleration here.
- The full cycle of re-encode/check/try again takes ~10s avg per image on a M1
  Pro, avg size savings 50%.
