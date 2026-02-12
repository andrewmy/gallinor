# Gallinor

This is a CLI tool for reducing the size of your video and image gallery while
maintaining quality. It supports almost no customization, focusing instead on
simplicity and ease of use.

## Features

- Support for macOS and Windows
- Video:
  - Reduce mp4 video file sizes, re-encoding everything to HEVC (H.265) with
    quality-based bitrate (VMAF score ≥ 90)
  - Support 720p, 1080p, and 4K videos
  - Support for Apple and NVidia hardware acceleration for video encoding
  - Support for CPU video encoding as a last resort
- Images:
  - Convert JPEGs to HEIC (default) with quality-based encoding (SSIMULACRA2
    score ≥ 85)
    - AVIF remains available via `--format=avif`
  - Archive ARW raw files with xz compression (~30% reduction)
  - Skip photos with no size benefit from conversion
  - Skip Samsung Portrait Mode and Live Photos (not sure about iOS)

## Requirements

- PHP 8.5 or higher
- Composer
- On Windows:
  - PowerShell
- For video:
  - FFmpeg with HEVC (H.265) support installed and available in your system PATH
    - macOS: `brew install ffmpeg`
  - For hardware acceleration:
    - macOS: Apple Silicon or Intel with VideoToolbox support
    - Win: NVidia GPU with NVENC support
  - For quality check — VMAF library installed and available in your system
    PATH, usually is in the box with ffmpeg
- For images:
  - ffmpeg (used for rotation-safe quality comparisons)
    - macOS: `brew install ffmpeg`
  - libheif (HEIC tools)
    - macOS: `brew install libheif`
    - Win:
      - MSYS2:
        - Install MSYS2, then: `pacman -S mingw-w64-x86_64-libheif`
        - Add `C:\\msys64\\mingw64\\bin` to PATH (so `heif-enc` and
          `heif-convert` are discoverable)
      - Conda (verify tools are present):
        - `conda install conda-forge::libheif`
        - Ensure `heif-enc` and `heif-convert` are available in that environment
    - Advanced: when `heif-enc` uses x265, Gallinor passes a couple x265 params
      via `heif-enc -p x265:...` to stabilize quality/size (AQ defaults).
  - libavif (only if using `--format=avif` or AVIF→HEIC migration)
    - macOS: `brew install libavif`
    - Win: <https://github.com/AOMediaCodec/libavif/releases>
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

## Installation

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
 php app.php videos:squeeze /path/to/videos [/path2 /path3 ...] [--dry-run]
```

The result files are saved along the originals with the `.optimal.mp4` suffix.

### Rename optimal videos to replace originals

```shell
php app.php videos:rename /path/to/videos [/path2 /path3 ...] [--dry-run]
```

After checking the quality, finish the job here.

### Process images

```shell
php app.php images:squeeze /path/to/photos [/path2 /path3 ...] [--dry-run] [--format=heic|avif] [--parallel] [--concurrency=N] [--worker-max-jobs=N] [--job-timeout=SECONDS]
```

Converts JPEGs to HEIC by default (saving alongside originals as `.heic`) and
archives ARW files per directory as `raws-N.tar.xz`.
HEIC encode/decode subprocesses run without Symfony's default 60s timeout to
avoid false failures on large frames.

Parallel mode is optional and applies only to JPEG optimisation. ARW archival
remains sequential. `--job-timeout` is an inactivity timeout (no worker message
for that job). Use `-v`/`-vv`/`-vvv` to print worker-pool lifecycle and
dispatch tracing while the progress bar is running. At `-vv` and above, Gallinor
also shows a live per-worker status panel under the progress bar (when running
in an ANSI/TTY console; otherwise it falls back to plain trace lines).

Worker status phase map (panel/trace view):

- `prepare` -> setup/normalization before probe loop
- `encode` -> encode candidate image at current quality
- `decode` -> decode candidate for quality comparison
- `score` -> SSIMULACRA2 measurement (`q`, `score`, `Δ` vs original)
- `decision` -> probe decision (`pass`, `score_low`, `too_big`,
  `quality_not_achieved`)
- `finalize` -> move final optimized file to target path
- `metadata` -> copy + verify metadata on final output

If you previously converted JPEGs to AVIF and need OneDrive compatibility:

```shell
php app.php images:migrate-avif-to-heic /path/to/photos [/path2 /path3 ...] [--dry-run] [--parallel] [--concurrency=N] [--worker-max-jobs=N] [--job-timeout=SECONDS]
php app.php images:remove-avifs /path/to/photos [/path2 /path3 ...] [--dry-run]
```

Parallel mode for AVIF→HEIC migration is optional and uses the same worker
pool controls as `images:squeeze`, including `-v`/`-vv`/`-vvv` tracing and the
live per-worker status panel at `-vv+`.

### Metadata verification failures

Gallinor verifies metadata after image conversion. If you see
`Metadata verification failed`, check the listed tags:

- Usually non-portable across container rewrite (often safe to ignore in future
  releases): `System:*`, `QuickTime:*`, `JSON:*`, vendor XMP blocks (for
  example `XMP-MiCamera:*`), maker-note projection tags
  (`MakerUnknown:Unknown_0xNNNN`, `ExifIFD:MakerNoteUnknown*`), Adobe Camera
  Raw local-correction/retouch tags (`XMP-crs:MaskGroupBasedCorr*`,
  `XMP-crs:RetouchArea*`), Photoshop camera-profile vignette tags
  (`XMP-photoshop:CameraProfilesPerspectiveModelVignetteModel*`), and private
  EXIF tags like `ExifIFD:Exif_0xNNNN`. Dimension projection tags
  (`ExifIFD:ImageWidth`, `ExifIFD:ImageHeight`) and derived brightness metric
  (`ExifIFD:BrightnessValue`) are also treated as non-portable.
- `ExifIFD:ShutterSpeedValue` can be rewritten with different numeric
  representation when `ExifIFD:ExposureTime` is preserved; Gallinor treats this
  pair as equivalent capture metadata.
- Potentially important and should stay strict: camera/exposure/date/GPS/lens
  metadata (`EXIF:*`, `DateTime*`, GPS, make/model).

If the failure includes important tags, stop and report it with the full tag
list. If it only includes non-portable tags, updating to a newer Gallinor build
may already include verifier compatibility rules.

If an ExifTool write step fails (for example while forcing `Orientation=1` on
HEIC), Gallinor now retries once for transient temp-file write/rename errors
and surfaces ExifTool stderr directly in the CLI error message. ExifTool
read/write metadata operations also use minor-error mode (`-m`) to tolerate
non-critical parser warnings in vendor blocks.

## Development

```shell
just ci
just smoke
```

`just ci` runs the default unit/static pipeline. `just smoke` runs separate
environment-dependent smoke tests (real CLI + toolchain + worker IPC) via
`php vendor/bin/phpunit tests/Smoke` using the main `phpunit.xml` config.

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
  a hard hat and fire up some movie while using it. Unfortunately NVENC does
  something funny to rotated videos so falling back to CPU there.

### Photo

- DNG files seem to be compressed well, `xz -9 -T0` gives ~2.5%, not worth it.
- While videos appear to degrade significantly with VMAF score below 90, photos
  seem to be perfectly fine with SSIMULACRA2 score 85.
- Unfortunately there is no GPU acceleration here.
- The full cycle of re-encode/check/try again takes ~10s avg per image on a M1
  Pro, avg size savings 50%.
