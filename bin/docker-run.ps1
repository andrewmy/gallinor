$ErrorActionPreference = "Stop"

$composeFiles = @("-f", "docker-compose.yml")
$nvidia = $false
$appArgs = @()
$volumes = @()
$pathIndex = 0
$seenCommand = $false

foreach ($arg in $args) {
    if ($arg -eq "--nvidia") {
        $nvidia = $true
    } elseif ($arg.StartsWith("-")) {
        $appArgs += $arg
    } elseif (-not $seenCommand) {
        # First positional arg = command name (images:squeeze, videos:squeeze, etc.)
        $appArgs += $arg
        $seenCommand = $true
    } elseif (Test-Path -Path $arg -PathType Container) {
        # Existing directory on the host — mount it into the container and
        # rewrite the arg so the app sees the container path instead.
        $hostPath = (Resolve-Path $arg).Path
        $containerPath = "/data/$pathIndex"
        $volumes += @("-v", "${hostPath}:${containerPath}")
        $appArgs += $containerPath
        $pathIndex++
    } else {
        $appArgs += $arg
    }
}

if ($nvidia) {
    # Preflight: check Compose version supports gpus field (2.30.0+)
    try {
        $composeVersion = docker compose version --short 2>&1
        $required = [version]"2.30.0"
        $found = [version]$composeVersion
        if ($found -lt $required) {
            throw "too old"
        }
    } catch {
        Write-Error "Error: Docker Compose 2.30.0+ required for GPU support (found $composeVersion)."
        Write-Error "Update Docker Desktop or install a newer Compose plugin."
        exit 1
    }

    # Preflight: check nvidia-smi is reachable inside a container.
    # The image itself doesn't contain nvidia-smi — the NVIDIA Container Toolkit
    # (nvidia-container-toolkit package) hooks into Docker and mounts the host's
    # GPU driver + nvidia-smi into the container at startup when --gpus is passed.
    # If this fails, the toolkit isn't installed or the GPU isn't accessible.
    $null = docker run --rm --gpus all debian:bookworm-slim nvidia-smi 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Error: NVIDIA GPU is not accessible inside Docker."
        Write-Error "Ensure the NVIDIA Container Toolkit is installed and docker supports --gpus."
        exit 1
    }

    $composeFiles += @("-f", "docker-compose.nvidia.yml")
}

& docker compose @composeFiles run --rm @volumes gallinor @appArgs
exit $LASTEXITCODE
