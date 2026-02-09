# Docs

This directory contains forward-looking design/planning documents. Most of them
are intentionally “decision heavy” and not necessarily implemented yet.

## Plans

- [QUALITY_SEARCH_UNIFICATION_PLAN.md](QUALITY_SEARCH_UNIFICATION_PLAN.md)
  - Unify the “probe → threshold → refine” mechanics across:
    - image HEIC quality search (JPEG→HEIC and AVIF→HEIC migration)
    - video bitrate search (VMAF-based retries + bounded refinement)
  - Explicitly keeps the JPEG→AVIF CQ grid (`20,18,…,2`) unchanged.
- [DOCKERIZATION_PLAN.md](DOCKERIZATION_PLAN.md)
  - Ship a pinned external toolchain by running Gallinor in a Linux container.
  - Makes setup more reliable and enables consistent tool versions (and future
    NVIDIA NVENC in Docker).

## Relationship map

- Dockerisation is orthogonal to both, but becomes the most reliable way to
  obtain the external toolchain needed by both images and video workflows.
