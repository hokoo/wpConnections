tests.init:
	cd ./local-dev/ && bash ./tests-init.sh

tests.run:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php sh -c 'vendor/bin/phpunit -c phpunit.xml && vendor/bin/phpunit -c php-wp-unit.xml'

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
	docker-compose -p wpconnections exec php sh -c 'composer run phpcs'

lint.phpcs.fix:
	cd ./local-dev/ && \
	docker-compose -p wpconnections exec php sh -c 'composer run phpcbf'
