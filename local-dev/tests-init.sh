#!/usr/bin/env bash

# Read the .env file
source ./.env

WP_DEV_REPO_NAME="wordpress-develop"
WP_DEV_DIR=../${WP_DEV_REPO_NAME}

if [ ! -d $WP_DEV_DIR ]; then
  branch="trunk"
  user="WordPress"
  zip_url="https://github.com/$user/$WP_DEV_REPO_NAME/archive/refs/heads/$branch.zip"

  echo -e "Downloading ${RYELLOW}$WP_DEV_REPO_NAME${COLOR_OFF} from ${RCYAN}$zip_url${COLOR_OFF}"
  curl -L "$zip_url" -o "$WP_DEV_REPO_NAME.zip"

  echo -e "Unzipping ${RYELLOW}$WP_DEV_REPO_NAME.zip${COLOR_OFF}"
  unzip "$WP_DEV_REPO_NAME.zip" > /dev/null
  mv "$WP_DEV_REPO_NAME-$branch" "$WP_DEV_DIR"
  rm "$WP_DEV_REPO_NAME.zip"
fi

echo -e "Installing ${RYELLOW}$WP_DEV_REPO_NAME${COLOR_OFF} dependencies"
if [ ! -f $WP_DEV_DIR/wp-tests-config.php ]; then

  echo -e "File ${RYELLOW}wp-tests-config.php${COLOR_OFF} doesn't exist. Recreating..."
  # copy the sample file
  cp "$WP_DEV_DIR"/wp-tests-config-sample.php $WP_DEV_DIR/wp-tests-config.php

  # remove all forward slashes in the end
  sed -i "s/youremptytestdbnamehere/$DB_TESTS_NAME/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s/yourusernamehere/$DB_USER/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s/yourpasswordhere/$DB_PASSWORD/" $WP_DEV_DIR/wp-tests-config.php
  sed -i "s|localhost|${DB_HOST}|" $WP_DEV_DIR/wp-tests-config.php
fi

echo -e "${RGREEN}Done.${COLOR_OFF}"
