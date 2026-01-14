.PHONY: help
help: # Show help for each of the Makefile recipes.
	@grep -E '^[a-zA-Z0-9 -]+:.*#'  Makefile | sort | while read -r l; do printf "\033[1;32m$$(echo $$l | cut -f 1 -d':')\033[00m:$$(echo $$l | cut -f 2- -d'#')\n"; done

.PHONY: ci
ci: composer-validate cbf require-check stan security-check # Full flow

.PHONY: composer-validate
composer-validate: # Validate composer.json
	composer validate

.PHONY: require-check
require-check: # Check for unused and misplaced dependencies
	php vendor/bin/composer-dependency-analyser

.PHONY: security-check
security-check: # Check for security vulnerabilities
	composer audit

.PHONY: cbf
cbf: # Fix code style
	php vendor/bin/phpcbf

.PHONY: stan
stan: # Run static analysis
	php vendor/bin/phpstan --memory-limit=-1

.PHONY: markdown
markdown: # Lint markdown, don't look at externally sourced files
	markdownlint README.md
