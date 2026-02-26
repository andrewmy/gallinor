set windows-shell := ["powershell.exe", "-NoLogo", "-Command"]

# Set DOCKER=1 to run PHP commands inside the Docker container
docker := env("DOCKER", "")
_dc := if docker != "" { "docker compose run --rm --entrypoint sh gallinor -c" } else { "" }
_q := if docker != "" { "'" } else { "" }

# Validate composer.json
composer-validate:
    {{ _dc }} {{ _q }}composer validate{{ _q }}

# Fix code style
cbf:
    {{ _dc }} {{ _q }}php vendor/bin/phpcbf{{ _q }}

# Check code style
lint:
    {{ _dc }} {{ _q }}php vendor/bin/phpcs{{ _q }}

# Run static analysis
stan:
    {{ _dc }} {{ _q }}php vendor/bin/phpstan --memory-limit=-1{{ _q }}

# Check for unused and misplaced dependencies
require-check:
    {{ _dc }} {{ _q }}php vendor/bin/composer-dependency-analyser{{ _q }}

# Check for security vulnerabilities
security-check:
    {{ _dc }} {{ _q }}composer audit{{ _q }}

# Lint markdown, don't look at externally sourced files (requires Node/npx on host)
markdown:
    npx --yes markdownlint-cli2 --config .markdownlint-cli2.jsonc .

# Run tests
test:
    {{ _dc }} {{ _q }}php vendor/bin/phpunit{{ _q }}

# Run smoke tests (toolchain + localhost IPC required)
smoke:
    {{ _dc }} {{ _q }}php vendor/bin/phpunit tests/Smoke{{ _q }}

# Check code coverage
coverage-check: test
    {{ _dc }} {{ _q }}php vendor/bin/coverage-check var/coverage.xml 29{{ _q }}

# Build Docker image and verify all tools are present
docker-smoke:
    docker compose build
    docker compose -f docker-compose.yml run --rm --entrypoint sh gallinor -c \
        'command -v ffmpeg ffprobe heif-enc heif-dec exiftool ssimulacra2 xz tar'
    docker compose -f docker-compose.yml run --rm --entrypoint sh gallinor -c \
        'ffmpeg -filters 2>&1 | grep -q libvmaf'
    docker compose -f docker-compose.yml run --rm --entrypoint sh gallinor -c \
        'heif-enc --list-encoders 2>&1 | grep -q x265'

# Full CI flow
# Full CI flow (markdown linting handled separately in CI via a dedicated GitHub Action)
ci: composer-validate lint require-check coverage-check stan security-check
