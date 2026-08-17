# Quality / Bitrate Search Unification Plan

## Goal

Unify the *search mechanics* used to find a minimum acceptable quality/bitrate
across:

- Images: HEIC quality (`q`) probing (JPEG→HEIC)
- Video: bitrate probing (MP4→HEVC with VMAF quality checks)

Without over-unifying the *probes themselves* (encode/decode/QC are domain
specific).

For concrete, video-only execution details (scout policy, stopping rules,
defaults, and tests), see
[VIDEO_SCOUT_SEARCH_PLAN.md](VIDEO_SCOUT_SEARCH_PLAN.md).

## Non-goals

- Do not change external tools or codecs.
- Do not introduce new CLI options.

## Related plans

- [README.md](README.md) (index)
- [Docker quick start](../README.md#quick-start-docker) (implemented toolchain
  standardisation; impacts how probes run)
- [VIDEO_SCOUT_SEARCH_PLAN.md](VIDEO_SCOUT_SEARCH_PLAN.md) (video-only
  decision-complete search policy)

## Current behavior (as of 2026-02-26)

### Images

- **JPEG→HEIC**:
  - coarse sweep: `q = 40..100 (step 10)` to find first passing bound
  - fine sweep: `q += 2` from last failing bound to first passing bound
  - requires output smaller than the source JPEG

### Video

- Start at a base bitrate by resolution.
- If VMAF is below the threshold, increase bitrate using an *adaptive step*
  based on the “distance” to the target VMAF.
- Current and planned video-specific search policy details are tracked in
  [VIDEO_SCOUT_SEARCH_PLAN.md#search-algorithm-remaining](VIDEO_SCOUT_SEARCH_PLAN.md#search-algorithm-remaining).

## Proposed shared abstraction: bounded monotone threshold refiner

### What we want to unify

Many flows have the same shape:

1. Probe a value (`q`, bitrate)
2. Evaluate a threshold (`SSIMULACRA2`, `VMAF`)
3. Optionally apply a size constraint (must be smaller than original)
4. Once we have a **fail/pass bracket**, refine to “close enough”

### What we do *not* unify

- How to encode/decode media
- How to compute the score (SSIMULACRA2 vs VMAF)
- How to log progress lines

### New helper

Add `src/Shared/Domain/MonotoneThresholdRefiner.php`:

- Inputs:
  - `lowFailValue` (known failing bound)
  - `highPassResult` (known passing bound + payload)
  - `granularity` (e.g. `2` for HEIC q, `100` for bitrate Kbps)
  - `maxOvershootRatio` (e.g. `1.10` for “within 10%”, or ~`1.0` for “minimum”)
  - `maxAttempts` (budget)
  - `probe(value): ProbeResult` (domain-provided)
  - `discard(result): void` (domain-provided cleanup, e.g. delete old temp file)
- Behavior:
  - Repeatedly probe midpoints (snapped down to `granularity`) until:
    - bracket is within `maxOvershootRatio`, or
    - the budget is exhausted, or
    - midpoint no longer moves
  - Keeps the “best passing” result and lets callers delete superseded temps.

Add minimal value objects in `src/Shared/Domain/`:

- `ProbeResult`:
  - `value` (int)
  - `passes` (bool)
  - `score` (float, for status/logging)
  - `sizeBytes` (int, for status/logging)
  - `payload` (opaque, e.g. temp file path)
- `RefineResult`:
  - `bestPass` (`ProbeResult`)
  - `attempts` (int)

## Applying the helper

### HEIC quality (Images)

Keep the existing coarse sweep to find the fail/pass bracket. Replace the fine
`q += 2` scan with `MonotoneThresholdRefiner`.

- `granularity = 2`
- JPEG→HEIC pass condition:
  - `SSIMULACRA2 >= 85` *and* `size < originalSize`
- `maxOvershootRatio` should be ~`1.0` to keep “minimum passing q” semantics

This unifies the refinement mechanics without changing probe semantics.

### Video bitrate (VMAF)

Use the shared refiner for the bounded fail/pass-bracket stage, while keeping
video scout policy and termination controls in the dedicated video plan:

- Algorithm and defaults:
  [VIDEO_SCOUT_SEARCH_PLAN.md#search-algorithm-remaining](VIDEO_SCOUT_SEARCH_PLAN.md#search-algorithm-remaining)
- Interface changes:
  [VIDEO_SCOUT_SEARCH_PLAN.md#interface--code-changes-remaining](VIDEO_SCOUT_SEARCH_PLAN.md#interface--code-changes-remaining)
- Acceptance expectations:
  [VIDEO_SCOUT_SEARCH_PLAN.md#acceptance-criteria-remaining-scope](VIDEO_SCOUT_SEARCH_PLAN.md#acceptance-criteria-remaining-scope)

## Tests

Add unit tests for the shared refiner:

- Refines until overshoot ratio met
- Respects granularity
- Respects max attempt budget

For video-specific scenarios, keep the authoritative list in
[VIDEO_SCOUT_SEARCH_PLAN.md#tests-remaining](VIDEO_SCOUT_SEARCH_PLAN.md#tests-remaining).
