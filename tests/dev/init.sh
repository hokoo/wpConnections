#!/usr/bin/env bash

# if .env file does not exist, copy the template
[ -f ./.env ] || cp ./dev/.env.template ./.env

# Read the .env file
source ./.env

WP_DEV_DIR_NAME=wordpress-develop
WP_DEV_DIR=../../${WP_DEV_DIR_NAME}

# if wordpress-develop directory does not exist, clone the repository
[ -d $WP_DEV_DIR ] || git clone https://github.com/WordPress/wordpress-develop $WP_DEV_DIR

if [ ! -f $WP_DEV_DIR/wp-tests-config.php ]; then
  # copy the sample file
  cp "$WP_DEV_DIR"/wp-tests-config-sample.php $WP_DEV_DIR/wp-tests-config.php

  # remove all forward slashes in the end
  sed -i "s/youremptytestdbnamehere/$DB_NAME/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s/yourusernamehere/$DB_USER/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s/yourpasswordhere/$DB_PASSWORD/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s|localhost|${DB_HOST}|" $WP_DEV_DIR/wp-tests-config.php
fi
