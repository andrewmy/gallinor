set windows-shell := ["powershell.exe", "-NoLogo", "-Command"]

# Validate composer.json
composer-validate:
    composer validate

# Fix code style
cbf:
    php vendor/bin/phpcbf

# Run static analysis
stan:
    php vendor/bin/phpstan --memory-limit=-1

# Check for unused and misplaced dependencies
require-check:
    php vendor/bin/composer-dependency-analyser

# Check for security vulnerabilities
security-check:
    composer audit

# Lint markdown, don't look at externally sourced files
markdown:
    markdownlint README.md

# Full CI flow
ci: composer-validate cbf require-check stan security-check
