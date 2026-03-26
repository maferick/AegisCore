.PHONY: help install install-dev lint test test-python test-php schema-check contract-lint fmt

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

install: ## Install production dependencies
	cd python && pip install -r requirements.txt
	cd php && composer install --no-dev

install-dev: ## Install all dependencies including dev
	cd python && pip install -r requirements.txt -r requirements-dev.txt
	cd php && composer install

fmt: ## Format Python code
	cd python && ruff format supplycore/ cli/ worker/ tests/

lint: ## Lint Python and PHP code
	cd python && ruff check supplycore/ cli/ worker/ tests/
	cd python && mypy supplycore/

test: test-python test-php ## Run all tests

test-python: ## Run Python tests
	cd python && python -m pytest tests/ -v

test-php: ## Run PHP tests
	cd php && ./vendor/bin/phpunit tests/

schema-check: ## Validate SQL queries against canonical schema
	python ci/schema_conformance.py

contract-lint: ## Validate job contracts against registry
	python ci/contract_lint.py
