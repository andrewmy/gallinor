# Video Scout + Near-Optimal Bitrate Search Plan

## Status

This document now tracks **remaining** work only.

Already implemented in baseline video flow:

- Adaptive upward bitrate retries when VMAF is below threshold.
- Downward probing from passing bitrate when there is quality headroom.
- Midpoint fail/pass bracket refinement with 10% overshoot stop condition.

Still not implemented (this plan scope):

- Scout-first decision pass for all videos.
- Unknown-resolution fallback base bitrate policy.
- Source-bitrate ceiling guard for upward search.
- Optional skip-reason semantics for scout-based skips.

## Goal

Capture hidden video space savings without turning `videos:squeeze` into a
runaway multi-encode workflow.

## Remaining Problem

Current video flow still has a hard "acceptable bitrate" skip. This is fast
but can miss meaningful savings.

Current flow also ties support to known resolution presets. Unknown resolutions
(for example WhatsApp transcodes) are often skipped because bitrate presets are
missing.

## Remaining Decisions

1. Default policy: scout first, then decide (for all videos).
2. Scout bitrate: `0.75 * source bitrate` (snapped to bitrate granularity).
3. Safety stop: bitrate ceiling only (no hard probe-count cap).
4. Full-search base:
   - known resolutions: keep current table-driven base bitrate
   - unknown resolutions: `0.50 * source bitrate` fallback base
5. Unknown resolutions are processed by default (no unsupported-resolution skip
   for bitrate search).
6. Scope: video-only change (images unchanged in this task).
7. CLI compatibility: no new user-facing flags.

## Search Algorithm (Remaining)

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

### 4) Existing refinement behavior (already implemented)

After first full-search pass, refine when a fail/pass bracket exists using
midpoint probing snapped to bitrate granularity.

Stop condition:

- `passingBitrate <= failingBitrate * 1.10`

## Interface / Code Changes (Remaining)

Add explicit bitrate-policy helpers in video domain logic so scout/base/ceiling
formulas are not duplicated across CLI and processor paths:

- scout bitrate derivation (`0.75 * source`)
- unknown-resolution full-search base (`0.50 * source`)
- source-bitrate ceiling guard

## Result Semantics (Optional)

Consider extending `App\Video\Domain\VideoProcessResult` with `skipReason` to
distinguish scout-fail skips from other skip paths.

## Tests (Remaining)

Add/update tests for:

1. All videos run scout at `0.75 * source` (snapped).
2. Scout fail returns skipped result (and reason if `skipReason` is added).
3. Scout pass triggers full-search flow.
4. Known resolutions keep current table-driven full-search base.
5. Unknown resolutions use `0.50 * source` full-search base and are processed.
6. Upward search respects source-bitrate ceiling.

## Acceptance Criteria (Remaining Scope)

1. Real savings are not systematically missed due to hard acceptable-bitrate
   skip.
2. Typical no-gain files terminate early through scout failure.
3. Search always terminates via source-bitrate ceiling.
4. Unknown-resolution videos (including WhatsApp-style transcodes) are handled
   by fallback bitrate policy instead of being skipped as unsupported.
5. No CLI flag changes are required for default behavior.
