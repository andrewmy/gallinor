# Video Scout + Near-Optimal Bitrate Search Plan

## Status

Proposed / not yet implemented.

## Goal

Capture hidden video space savings without turning `videos:squeeze` into a
runaway multi-encode workflow.

## Problem

Current video flow has a hard "acceptable bitrate" skip. This is fast but can
miss meaningful savings. Removing that skip entirely would recover savings but
can explode runtime because each probe is a full encode + VMAF pass.

Current flow also ties support to known resolution presets. Unknown resolutions
(for example WhatsApp transcodes) are often skipped because bitrate presets are
missing.

## Decisions

1. Default policy: scout first, then decide (for all videos).
2. Search target: near-optimal bitrate within 10% fail/pass overshoot.
3. Scout bitrate: `0.75 * source bitrate` (snapped to bitrate granularity).
4. Safety stop: bitrate ceiling only (no hard probe-count cap).
5. Full-search base:
   - known resolutions: current table-driven base bitrate
   - unknown resolutions: `0.50 * source bitrate` fallback base
6. Unknown resolutions are processed by default (no "unsupported resolution"
   skip path for bitrate search).
7. Scope: video-only change (images remain unchanged in this task).
8. CLI compatibility: no new user-facing flags.

## Search Algorithm

### 1) Scout pass for all videos

Run a first scout probe for every video:

- `scoutBitrate = snapToGranularity(sourceBitrateKbps * 0.75)`
- Probe is a normal encode + VMAF check at scout bitrate

Decision:

- Scout fail: skip file (not an error)
- Scout pass: continue to full bitrate search

### 2) Full-search base selection

Choose initial full-search bitrate:

- Known resolution: keep current base-bitrate table behavior.
- Unknown resolution: `searchBase = snapToGranularity(sourceBitrateKbps * 0.50)`.

### 3) Upward adaptive search with ceiling

From the selected base bitrate, keep adaptive upward stepping when VMAF is
below threshold.

Termination guard:

- never probe above source bitrate
- `maxSearchBitrateKbps = currentSourceBitrateKbps`

### 4) Bracket refinement

After first full-search pass, refine when a fail/pass bracket exists. Use
midpoint probing snapped to bitrate granularity.

Refinement stop condition:

- `passingBitrate <= failingBitrate * 1.10`

This intentionally prefers near-optimal bitrate over absolute minimum bitrate.

## Interface / Code Changes

### Bitrate policy API

Add explicit bitrate-policy helpers in video domain logic so scout/base/ceiling
formulas are not duplicated across CLI and processor paths:

- scout bitrate derivation (`0.75 * source`)
- unknown-resolution full-search base (`0.50 * source`)
- source-bitrate ceiling guard

## Result semantics

Extend `App\Video\Domain\VideoProcessResult` with `skipReason` to distinguish
opportunistic scout skips from other skip paths.

## Tests

Add/update tests for:

1. All videos run scout at `0.75 * source` (snapped).
2. Scout fail returns skipped result with reason.
3. Scout pass triggers full-search flow.
4. Known resolutions keep current table-driven full-search base.
5. Unknown resolutions use `0.50 * source` full-search base and are processed.
6. Upward search respects source-bitrate ceiling.
7. Refinement converges to 10% overshoot boundary.

## Acceptance Criteria

1. Real savings are not systematically missed due to hard acceptable-bitrate
   skips.
2. Typical no-gain files terminate early through scout failure.
3. Search always terminates via bitrate ceiling.
4. Final bitrate is near-optimal (10% fail/pass window), not arbitrary.
5. Unknown-resolution videos (including WhatsApp-style transcodes) are handled
   by fallback bitrate policy instead of being skipped as unsupported.
6. No CLI flag changes are required for default behavior.
