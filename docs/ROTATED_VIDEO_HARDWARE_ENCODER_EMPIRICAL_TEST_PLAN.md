# Rotated Video Hardware Encoder Empirical Test Plan

## Purpose

This runbook reproduces rotated-video quality and geometry behavior across
hardware encoders.

Primary goals:

1. Verify that rotated sources (Display Matrix metadata) do not cause geometry
   drift or VMAF failures.
2. Compare hardware encoder behavior against CPU (`libx265`) under
   Gallinor-like flags.
3. Produce evidence to decide per-encoder fallback policy for rotated files.

This document captures the empirical procedure used during the Apple
VideoToolbox investigation and extends it for NVIDIA NVENC reproduction on a
different machine.

## Scope

In scope:

- Synthetic clips with and without Display Matrix rotation.
- Supported Gallinor resolutions: `720p`, `1080p`, `4K`.
- Encoders: `libx265` (reference), `hevc_videotoolbox` (macOS),
  `hevc_nvenc` (NVIDIA machine).
- VMAF scoring with Gallinor's decode-order alignment strategy.

Out of scope:

- Subjective visual assessment.
- Battery/power benchmarks.
- Cross-codec comparisons (AV1, H.264 output, etc.).

## Preconditions

1. `ffmpeg` v8+ and `ffprobe` installed.
2. `libvmaf` filter available in ffmpeg build.
3. For macOS VT run: `hevc_videotoolbox` encoder available.
4. For NVIDIA run: `hevc_nvenc` encoder available.
5. Gallinor repo checked out (for command parity and references).

Quick capability check:

```bash
ffmpeg -hide_banner -version
ffmpeg -hide_banner -encoders | rg 'hevc_(videotoolbox|nvenc)|libx265'
ffmpeg -hide_banner -filters | rg 'libvmaf|vmafmotion'
ffmpeg -hide_banner -h encoder=hevc_videotoolbox | sed -n '1,220p'
ffmpeg -hide_banner -h encoder=hevc_nvenc | sed -n '1,260p'
```

Expected:

- `libvmaf` present (required; `vmafmotion` alone is not enough).
- At least one hardware encoder present on target machine.

## Important Rotation Detail

Do not rely on `-metadata:s:v rotate=90` for this test. In some builds it does
not create `Display Matrix` side data. Gallinor detects rotation via
`Display Matrix`, so test assets must include that side data.

Use `-display_rotation` on input remux to inject proper Display Matrix data.

## Workspace

Use a dedicated temp directory:

```bash
TMP_DIR="/tmp/gallinor_rotated_encoder_matrix"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
```

## Phase A: Generate Sources (Plain + Display Matrix Rotated)

Create base synthetic clips and remux rotated variants with Display Matrix.

```bash
set -euo pipefail

mk_src() {
  local name="$1"    # e.g. src_1080p_plain
  local size="$2"    # e.g. 1920x1080
  local rot="$3"     # 0, 90, 270
  local base="$TMP_DIR/${name}_base.mp4"
  local out="$TMP_DIR/${name}.mp4"

  ffmpeg -hide_banner -loglevel error \
    -f lavfi -i "testsrc2=size=${size}:rate=30" -t 3 \
    -c:v libx264 -pix_fmt yuv420p -movflags +faststart -y "$base"

  if [ "$rot" = "0" ]; then
    mv "$base" "$out"
  else
    ffmpeg -hide_banner -loglevel error \
      -display_rotation:v:0 "$rot" -i "$base" \
      -c copy -movflags +faststart -y "$out"
    rm -f "$base"
  fi
}

mk_src "src_720p_plain"   "1280x720"  "0"
mk_src "src_720p_rot90"   "1280x720"  "90"
mk_src "src_1080p_plain"  "1920x1080" "0"
mk_src "src_1080p_rot90"  "1920x1080" "90"
mk_src "src_1080p_rot270" "1920x1080" "270"
mk_src "src_4k_rot90"     "3840x2160" "90"
```

Validate source rotation metadata:

```bash
for f in "$TMP_DIR"/src_*.mp4; do
  ffprobe -hide_banner -v error -show_streams -select_streams v:0 -of json "$f" \
    | jq -c --arg file "$(basename "$f")" \
      '{file:$file,w:.streams[0].width,h:.streams[0].height,rot:([.streams[0].side_data_list[]? | select(.side_data_type=="Display Matrix") | .rotation]|first)}'
done
```

Expected:

- Rotated variants have `rot` `90` or `-90`.
- Plain variants have `rot` `null`.

## Phase B: Encode Matrix With Gallinor-Like Flags

### CPU Reference (`libx265`)

```bash
encode_cpu() {
  local src="$1"
  local out="$2"
  local bitrate="$3"

  ffmpeg -hide_banner -loglevel error \
    -fflags +genpts -i "$src" \
    -c:a copy -c:v libx265 \
    -b:v "${bitrate}k" \
    -pix_fmt yuv420p10le -profile:v main10 \
    -tag:v hvc1 -map_metadata 0 -movflags +use_metadata_tags+faststart \
    -preset medium -x265-params pools=8 \
    -fps_mode passthrough -y "$out"
}
```

### Apple VideoToolbox (macOS)

```bash
encode_vt() {
  local src="$1"
  local out="$2"
  local bitrate="$3"
  local maxrate=$(( bitrate * 125 / 100 ))

  ffmpeg -hide_banner -loglevel error \
    -fflags +genpts -i "$src" \
    -c:a copy -c:v hevc_videotoolbox \
    -b:v "${bitrate}k" -maxrate:v "${maxrate}k" \
    -quality quality -prio_speed 0 -realtime 0 -spatial_aq 1 -power_efficient 0 \
    -pix_fmt yuv420p10le -profile:v main10 \
    -tag:v hvc1 -map_metadata 0 -movflags +use_metadata_tags+faststart \
    -fps_mode passthrough -y "$out"
}
```

### NVIDIA NVENC (NVIDIA machine)

Use flags matching Gallinor's NVENC path as closely as possible:

```bash
encode_nvenc() {
  local src="$1"
  local out="$2"
  local bitrate="$3"
  local maxrate=$(( bitrate * 125 / 100 ))

  ffmpeg -hide_banner -loglevel error \
    -hwaccel cuda -hwaccel_output_format cuda \
    -fflags +genpts -i "$src" \
    -c:a copy -c:v hevc_nvenc \
    -b:v "${bitrate}k" -maxrate:v "${maxrate}k" \
    -preset p7 -rc vbr -spatial_aq 1 -aq-strength 12 \
    -cq 21 -rc-lookahead 32 -multipass fullres \
    -temporal-aq 1 -bf 4 -b_ref_mode middle \
    -profile:v main10 \
    -tag:v hvc1 -map_metadata 0 -movflags +use_metadata_tags+faststart \
    -fps_mode passthrough -y "$out"
}
```

Notes for NVENC:

- Some options vary by ffmpeg build or GPU support. If an option is rejected,
  remove only the unsupported option and re-run.
- Gallinor capability-gates these in code; this script is an aggressive parity
  baseline for investigation.

### Run Encodes

Use Gallinor default base bitrates for initial pass:

- `720p`: `4000k`
- `1080p`: `8000k`
- `4K`: `28000k`

Example loop:

```bash
for src in "$TMP_DIR"/src_*.mp4; do
  name="$(basename "$src" .mp4)"

  case "$name" in
    src_720p_*) bitrate=4000 ;;
    src_1080p_*) bitrate=8000 ;;
    src_4k_*) bitrate=28000 ;;
    *) echo "Unknown source bucket: $name" >&2; exit 1 ;;
  esac

  encode_cpu "$src" "$TMP_DIR/${name}_cpu.mp4" "$bitrate"

  if ffmpeg -hide_banner -encoders | rg -q hevc_videotoolbox; then
    encode_vt "$src" "$TMP_DIR/${name}_vt.mp4" "$bitrate"
  fi

  if ffmpeg -hide_banner -encoders | rg -q hevc_nvenc; then
    encode_nvenc "$src" "$TMP_DIR/${name}_nvenc.mp4" "$bitrate"
  fi
done
```

## Phase C: Geometry and Rotation Validation

Collect display and coded dimensions and rotation side data:

```bash
for f in "$TMP_DIR"/src_*.mp4 "$TMP_DIR"/*_cpu.mp4 "$TMP_DIR"/*_vt.mp4 "$TMP_DIR"/*_nvenc.mp4; do
  [ -f "$f" ] || continue
  ffprobe -hide_banner -v error -show_streams -select_streams v:0 -of json "$f" \
    | jq -c --arg file "$(basename "$f")" \
      '{file:$file,codec:.streams[0].codec_name,w:.streams[0].width,h:.streams[0].height,cw:.streams[0].coded_width,ch:.streams[0].coded_height,pix:.streams[0].pix_fmt,rot:([.streams[0].side_data_list[]? | select(.side_data_type=="Display Matrix") | .rotation]|first)}'
done
```

Checks:

1. Rotated source with Display Matrix should decode to portrait outputs:
   `1920x1080 + rot=90` source typically becomes `1080x1920` output with no
   remaining rotation side data.
2. No unexpected display-dimension distortion.
3. Coded-dimension padding (for example `1088`) can happen; treat as expected
   if display dimensions are correct and VMAF pipeline still works.

## Phase D: VMAF Validation (Gallinor-Compatible)

Use Gallinor's decode-order alignment:

```bash
vmaf_pair() {
  local src="$1"
  local dist="$2"
  local log="$3"

  ffmpeg -hide_banner -nostats -loglevel error \
    -i "$src" -i "$dist" \
    -filter_complex "[0:v]settb=AVTB,setpts=N[reference];[1:v]settb=AVTB,setpts=N[distorted];[distorted][reference]libvmaf=log_path=${log}:log_fmt=xml:n_threads=8:n_subsample=10" \
    -f null - >/dev/null
}
```

Extract mean VMAF from XML log:

```bash
extract_vmaf_mean() {
  local log="$1"
  rg 'metric name="vmaf".*mean="([0-9.]+)"' -or '$1' "$log" | head -n1
}
```

Run across outputs:

```bash
for src in "$TMP_DIR"/src_*.mp4; do
  base="${src%.mp4}"
  for kind in cpu vt nvenc; do
    out="${base}_${kind}.mp4"
    [ -f "$out" ] || continue
    log="$TMP_DIR/vmaf_$(basename "$base")_${kind}.xml"
    vmaf_pair "$src" "$out" "$log"
    echo "$(basename "$src"),${kind},$(extract_vmaf_mean "$log")"
  done
done
```

## Pass/Fail Criteria

For each rotated source and encoder:

1. Encode command succeeds (no ffmpeg failure).
2. VMAF run succeeds (no libvmaf dimension mismatch or framesync error).
3. Output dimensions represent correct presentation geometry.
4. Mean VMAF at or above `90.0` at target bitrate after Gallinor search
   behavior.

Encoder policy decision guidance:

- If NVENC fails geometry or repeatedly fails VMAF alignment on rotated clips,
  keep or enforce CPU fallback for NVENC + rotated.
- If VT remains stable on rotated clips with successful VMAF and correct
  geometry, allow VT for rotated on macOS.

## Suggested Result Table

Store result rows in CSV/markdown with these columns:

1. `source_name`
2. `resolution`
3. `rotation_side_data`
4. `encoder`
5. `target_bitrate_kbps`
6. `output_width`
7. `output_height`
8. `coded_width`
9. `coded_height`
10. `output_rotation_side_data`
11. `output_size_bytes`
12. `vmaf_mean`
13. `status` (`pass`/`fail`)
14. `notes`

## Known Pitfalls

1. Sandbox/virtualized execution can block hardware sessions. On macOS this can
   surface as VideoToolbox compression-session errors (for example `-12908`).
2. libvmaf default log format may be XML; always set `log_fmt=xml` or parse
   accordingly.
3. 1080p hardware outputs may use padded coded dimensions (`1088`) while
   display dimensions remain `1080`; this is not automatically a failure.
4. `rotate` metadata tag alone may not trigger Gallinor's rotation detection;
   ensure `Display Matrix` side data exists.

## Mapping Back to Gallinor

Relevant Gallinor code paths to compare against run outputs:

- Encoder selection for rotated sources:
  `src/Video/Infrastructure/FfmpegEncoder.php`
- Command construction by encoder:
  `src/Video/Infrastructure/FfmpegEncoder.php`
- VMAF filter graph:
  `src/Video/Infrastructure/FfmpegEncoder.php`
- Supported resolution buckets:
  `src/Video/Domain/VideoFile.php`

When reproducing on NVIDIA hardware, attach:

1. `ffmpeg -encoders` output.
2. `ffmpeg -h encoder=hevc_nvenc` output.
3. Stream summary JSON/CSV.
4. VMAF logs and extracted means.
5. A short conclusion with fallback recommendation.
