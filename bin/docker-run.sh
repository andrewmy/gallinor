#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILES=(-f docker-compose.yml)
NVIDIA=false
INTEL=false
ARGS=()
VOLUMES=()
RUN_ARGS=(--rm --user "$(id -u):$(id -g)")
path_index=0
seen_command=false

for arg in "$@"; do
    if [[ "$arg" == "--nvidia" ]]; then
        NVIDIA=true
    elif [[ "$arg" == "--intel" ]]; then
        INTEL=true
    elif [[ "$arg" == -* ]]; then
        ARGS+=("$arg")
    elif ! $seen_command; then
        # First positional arg = command name (images:squeeze, videos:squeeze, etc.)
        ARGS+=("$arg")
        seen_command=true
    elif [[ -d "$arg" ]]; then
        # Existing directory on the host — mount it into the container and
        # rewrite the arg so the app sees the container path instead.
        host_path=$(cd "$arg" && pwd)
        container_path="/data/${path_index}"
        VOLUMES+=(-v "${host_path}:${container_path}")
        ARGS+=("$container_path")
        path_index=$((path_index + 1))
    else
        ARGS+=("$arg")
    fi
done

if $NVIDIA && $INTEL; then
    echo "Error: --nvidia and --intel are mutually exclusive." >&2
    exit 1
fi

if $NVIDIA; then
    # Preflight: check Compose version supports gpus field (2.30.0+)
    compose_version=$(docker compose version --short 2>/dev/null || echo "0.0.0")
    required="2.30.0"
    if [[ "$(printf '%s\n' "$required" "$compose_version" | sort -V | head -n1)" != "$required" ]]; then
        echo "Error: Docker Compose $required+ required for GPU support (found $compose_version)." >&2
        echo "Update Docker Desktop or install a newer Compose plugin." >&2
        exit 1
    fi

    # Preflight: check nvidia-smi is reachable inside a container.
    # The image itself doesn't contain nvidia-smi — the NVIDIA Container Toolkit
    # (nvidia-container-toolkit package) hooks into Docker and mounts the host's
    # GPU driver + nvidia-smi into the container at startup when --gpus is passed.
    # If this fails, the toolkit isn't installed or the GPU isn't accessible.
    if ! docker run --rm --gpus all debian:bookworm-slim nvidia-smi > /dev/null 2>&1; then
        echo "Error: NVIDIA GPU is not accessible inside Docker." >&2
        echo "Ensure the NVIDIA Container Toolkit is installed and docker supports --gpus." >&2
        exit 1
    fi

    COMPOSE_FILES+=(-f docker-compose.nvidia.yml)
fi

if $INTEL; then
    if [[ "$(uname -s)" != "Linux" ]]; then
        echo "Error: Intel Docker acceleration requires a Linux host with /dev/dri access." >&2
        exit 1
    fi

    if [[ ! -d /dev/dri ]]; then
        echo "Error: /dev/dri is not available on this host." >&2
        echo "Ensure Intel iGPU drivers are loaded and render nodes are exposed." >&2
        exit 1
    fi

    if ! docker run --rm --device /dev/dri:/dev/dri debian:bookworm-slim sh -lc 'ls /dev/dri/renderD* >/dev/null 2>&1'; then
        echo "Error: Intel render node is not accessible inside Docker." >&2
        echo "Ensure Docker can map /dev/dri and your user has render-device permissions." >&2
        exit 1
    fi

    # Keep host render/video access by adding matching device group IDs.
    dri_groups=()
    for node in /dev/dri/renderD* /dev/dri/card*; do
        [[ -e "$node" ]] || continue
        gid=$(stat -c '%g' "$node")
        seen=false
        for existing in "${dri_groups[@]}"; do
            if [[ "$existing" == "$gid" ]]; then
                seen=true
                break
            fi
        done
        if ! $seen; then
            dri_groups+=("$gid")
        fi
    done

    for gid in "${dri_groups[@]}"; do
        RUN_ARGS+=(--group-add "$gid")
    done

    COMPOSE_FILES+=(-f docker-compose.intel.yml)
fi

exec docker compose "${COMPOSE_FILES[@]}" run \
    "${RUN_ARGS[@]}" \
    ${VOLUMES[@]+"${VOLUMES[@]}"} \
    gallinor "${ARGS[@]}"
