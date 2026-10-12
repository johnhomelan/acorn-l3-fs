# Uses the phpunit installed by composer when there is one, otherwise the system wide phpunit
PHPUNIT := $(if $(wildcard vendor/bin/phpunit),vendor/bin/phpunit,phpunit)
PHP ?= php

.PHONY: all test lint install

all: lint test

# Runs the unit tests in unit-tests/
test:
	$(PHPUNIT) --configuration phpunit.xml.dist

# Checks every php file for syntax errors
lint:
	@for f in src/*.php unit-tests/*.php utils/*.php; do $(PHP) -l $$f > /dev/null || exit 1; done; echo "No syntax errors"

# Installs phpunit and the autoloader via composer
install:
	composer install
