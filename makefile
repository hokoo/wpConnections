.PHONY: tests.init tests.run tests.phpunit tests.integration tests.wpunit tests.build tests.rebuild tests.clean dev.install docker.up docker.down docker.build.php php.connect php.log lint.phpcs lint.phpcs.fix

tests.init:
	cd ./local-dev/ && bash ./tests-init.sh

tests.run:
	cd ./local-dev/ && \
	docker-compose -p wpconnections run --rm phpunit test:all

tests.phpunit:
	cd ./local-dev/ && \
	docker-compose -p wpconnections run --rm phpunit test:phpunit

tests.integration:
	cd ./local-dev/ && \
	docker-compose -p wpconnections run --rm phpunit test:integration

tests.wpunit: tests.integration

tests.build:
	cd ./local-dev/ && \
	docker-compose -p wpconnections build phpunit

tests.rebuild:
	cd ./local-dev/ && \
	docker-compose -p wpconnections build --no-cache phpunit

tests.clean: tests.rebuild tests.run

dev.install:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php sh -c 'composer install && bash ./local-dev/wp-init.sh'

docker.up:
	cd ./local-dev/ && \
	docker-compose -p wpconnections up -d

docker.down:
	cd ./local-dev/ && \
	docker-compose -p wpconnections down

docker.build.php:
	cd ./local-dev/ && \
	docker-compose -p wpconnections up -d --build php

php.connect:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php bash

php.log:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php sh -c 'tail -n 50 -f /var/log/php/error.log | grcat grc.conf'

lint.phpcs:
	cd ./local-dev/ && \
	docker-compose -p wpconnections run --rm phpunit cs:phpcs

lint.phpcs.fix:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php sh -c 'composer run phpcbf'
