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
  - Convert JPEGs to AVIF with quality-based encoding (SSIMULACRA2 score ≥ 85)
  - Archive ARW raw files with xz compression (~30% reduction)
  - Skip photos with no size benefit from AVIF conversion
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
  - libavif
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
php app.php images:squeeze /path/to/photos [/path2 /path3 ...] [--dry-run]
```

Converts JPEGs to AVIF (saving alongside originals as `.avif`) and archives ARW
files per directory as `raws-N.tar.xz`.

## Development

```shell
just ci
```

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
