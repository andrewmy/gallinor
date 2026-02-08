# Docs

This directory contains forward-looking design/planning documents. Most of them
are intentionally “decision heavy” and not necessarily implemented yet.

## Plans

- [QUALITY_SEARCH_UNIFICATION_PLAN.md](QUALITY_SEARCH_UNIFICATION_PLAN.md)
  - Unify the “probe → threshold → refine” mechanics across:
    - image HEIC quality search (JPEG→HEIC and AVIF→HEIC migration)
    - video bitrate search (VMAF-based retries + bounded refinement)
  - Explicitly keeps the JPEG→AVIF CQ grid (`20,18,…,2`) unchanged.
- [PARALLEL_PROCESSING_PLAN.md](PARALLEL_PROCESSING_PLAN.md)
  - Add an in-app worker pool to parallelise JPEG optimisation in
    `images:squeeze`.
  - Complements the quality-search work by reducing wall-clock time for the
    “many probes per file” flows.
- [DOCKERIZATION_PLAN.md](DOCKERIZATION_PLAN.md)
  - Ship a pinned external toolchain by running Gallinor in a Linux container.
  - Makes setup more reliable and enables consistent tool versions (and future
    NVIDIA NVENC in Docker).

## Relationship map

- Parallel image processing improves runtime for the quality-search loops (more
  encodes/decodes/QC in less wall-clock time).
- Dockerisation is orthogonal to both, but becomes the most reliable way to
  obtain the external toolchain needed by both images and video workflows.
