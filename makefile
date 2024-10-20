tests.init:
	cd ./tests/dev/ && bash ./init.sh

tests.docker.up:
	cd ./tests/dev/ && docker-compose -p wpc-tests up -d

tests.docker.down:
	cd ./tests/dev/ && docker-compose -p wpc-tests down

tests.docker.build:
	cd ./tests/dev/ && docker-compose -p wpc-tests up -d --build php

tests.docker.connect:
	cd ./tests/dev/ && docker-compose -p wpc-tests exec php bash

tests.run:
	cd ./tests/dev/ && \
	docker-compose -p wpc-tests exec php sh -c 'vendor/bin/phpunit -c phpunit.xml && vendor/bin/phpunit -c php-wp-unit.xml'