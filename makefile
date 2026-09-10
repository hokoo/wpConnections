COVERAGE_IMAGE := wpconnections-coverage:php8.1.34-wp6.7.7
COVERAGE_PHP_VERSION := 8.1.34
COVERAGE_WP_VERSION := 6.7.7

.PHONY: tests.init tests.run tests.phpunit tests.integration tests.coverage tests.build tests.rebuild tests.clean dev.install docker.up docker.down docker.build.php php.connect php.log lint.phpcs lint.phpcs.fix

tests.init:
	cd ./local-dev/ && bash ./tests-init.sh

tests.run:
	cd ./local-dev/ && \
	docker compose -p wpconnections run --rm phpunit test:all

tests.phpunit:
	cd ./local-dev/ && \
	docker compose -p wpconnections run --rm phpunit test:phpunit

tests.integration:
	cd ./local-dev/ && \
	docker compose -p wpconnections run --rm phpunit test:integration

tests.coverage:
	docker build \
		--build-arg PHP_VERSION=$(COVERAGE_PHP_VERSION) \
		--build-arg WP_VERSION=$(COVERAGE_WP_VERSION) \
		-t $(COVERAGE_IMAGE) \
		-f Dockerfile.phpunit .
	docker run --rm -v "$(CURDIR):/srv/web" $(COVERAGE_IMAGE) test:coverage

tests.build:
	cd ./local-dev/ && \
	docker compose -p wpconnections build phpunit

tests.rebuild:
	cd ./local-dev/ && \
	docker compose -p wpconnections build --no-cache phpunit

tests.clean: tests.rebuild
	cd ./local-dev/ && \
	docker compose -p wpconnections run --rm phpunit test:all

dev.install:
	cd ./local-dev/ && \
	docker compose -p wpconnections exec php sh -c 'composer install && bash ./local-dev/wp-init.sh'

docker.up:
	cd ./local-dev/ && \
	docker compose -p wpconnections up -d

docker.down:
	cd ./local-dev/ && \
	docker compose -p wpconnections down

docker.build.php:
	cd ./local-dev/ && \
	docker compose -p wpconnections up -d --build php

php.connect:
	cd ./local-dev/ && \
	docker compose -p wpconnections exec php bash

php.log:
	cd ./local-dev/ && \
	docker compose -p wpconnections exec php sh -c 'tail -n 50 -f /var/log/php/error.log | grcat grc.conf'

lint.phpcs:
	cd ./local-dev/ && \
	docker compose -p wpconnections run --rm phpunit cs:phpcs

lint.phpcs.fix:
	cd ./local-dev/ && \
	docker compose -p wpconnections exec php sh -c 'composer run phpcbf'
