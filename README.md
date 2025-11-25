# Gallinor

This is a CLI tool for reducing the size of your video and image gallery while maintaining quality. It supports almost no customization, focusing instead on simplicity and ease of use.

## Features

- Reduce mp4 video file sizes, re-encoding everything to HEVC (H.265) with MVP bitrate
- Support 720p, 1080p, and 4K videos
- Support for Apple and NVidia hardware acceleration for video encoding
- Support for CPU video encoding
- Support for macOS and Windows
- Video quality check and bitrate adjustment

## Todo

- [ ] Reduce jpg image file sizes

## Requirements

- PHP 8.4 or higher
- Composer
- FFmpeg with HEVC (H.265) support installed and available in your system PATH
- For hardware acceleration:
  - macOS: Apple Silicon or Intel with VideoToolbox support
  - Windows: NVidia GPU with NVENC support
- For quality check — VMAF library installed and available in your system PATH
- On Windows:
  - PowerShell

## Installation

```shell
composer install
```

## Usage

For the full list of options and their descriptions:

```shell
php app.php help videos
```

### Crush some vids

```shell
 php app.php videos /path/to/videos [/path2 /path3 ...] [--dry-run] [--check-quality]
```

The result files are saved along the originals with the `.optimal.mp4` suffix.

### Rename optimals to replace originals

```shell
php app.php rename /path/to/videos [/path2 /path3 ...] [--dry-run]
```

If you ran the `videos` command without `--replace-originals` as you should, after checking the quality, finish the job here.

## Notes

NVENC seems to achieve better visual quality with smaller bitrate. On a selection of complex 1080p videos with source bitrate 16 Mbps, to achieve VMAF score 90+:
- Apple VideoToolbox needed 12-14 Mbps or fails completely;
- NVENC needed 8-12 Mbps.

The CPU encoder is very slow and its CRF rate is not really well tested, wear a hard hat and fire up some movie while using it.
