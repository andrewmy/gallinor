# Docs

This directory contains forward-looking design/planning documents. Most of them
are intentionally “decision heavy” and not necessarily implemented yet.

## Plans

- [QUALITY_SEARCH_UNIFICATION_PLAN.md](QUALITY_SEARCH_UNIFICATION_PLAN.md)
  - Unify the “probe → threshold → refine” mechanics across:
    - image HEIC quality search (JPEG→HEIC)
    - video bitrate search (VMAF-based retries + bounded refinement)
- [VIDEO_SCOUT_SEARCH_PLAN.md](VIDEO_SCOUT_SEARCH_PLAN.md)
  - Video-only execution plan that balances hidden savings discovery against
    extra encode/VMAF runtime using scout probes and near-optimal refinement.
- [ROTATED_VIDEO_HARDWARE_ENCODER_EMPIRICAL_TEST_PLAN.md](ROTATED_VIDEO_HARDWARE_ENCODER_EMPIRICAL_TEST_PLAN.md)
  - Step-by-step empirical runbook to reproduce rotated-video geometry and VMAF
    behavior across CPU, Apple VideoToolbox, and NVIDIA NVENC.

## Relationship map

- Dockerisation (implemented — see root `Dockerfile` and `README.md`) is
  orthogonal to both plans above, but provides the most reliable way to obtain
  the external toolchain needed by both images and video workflows.
