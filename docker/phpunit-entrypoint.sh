#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-wordpress_test}"
DB_USER="${DB_USER:-wordpress}"
DB_PASSWORD="${DB_PASSWORD:-wordpress}"
WP_CORE_DIR="${WP_CORE_DIR:-/opt/wordpress}"
WP_DEVELOP_DIR="${WP_DEVELOP_DIR:-/opt/wordpress-develop}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/opt/wordpress-develop/tests/phpunit}"
MYSQL_SOCKET="${MYSQL_SOCKET:-/run/mysqld/mysqld.sock}"
MYSQL_DATA_DIR="${MYSQL_DATA_DIR:-/tmp/mysql-data}"
CONFIG_SAMPLE="${WP_DEVELOP_DIR}/wp-tests-config-sample.php"
CONFIG_FILE="${WP_DEVELOP_DIR}/wp-tests-config.php"

MYSQL_RUNTIME_USER="${MYSQL_RUNTIME_USER:-$(id -un 2>/dev/null || echo root)}"
MYSQL_RUN_DIR="$(dirname "${MYSQL_SOCKET}")"

mkdir -p "${MYSQL_RUN_DIR}" "${MYSQL_DATA_DIR}"

if [ "$(id -u)" -eq 0 ]; then
  chown -R "${MYSQL_RUNTIME_USER}" "${MYSQL_RUN_DIR}" "${MYSQL_DATA_DIR}"
fi

if [ ! -d "${MYSQL_DATA_DIR}/mysql" ]; then
  mariadb-install-db \
    --user="${MYSQL_RUNTIME_USER}" \
    --datadir="${MYSQL_DATA_DIR}" \
    --skip-test-db \
    --auth-root-authentication-method=normal >/dev/null
fi

mariadbd \
  --user="${MYSQL_RUNTIME_USER}" \
  --datadir="${MYSQL_DATA_DIR}" \
  --socket="${MYSQL_SOCKET}" \
  --bind-address=127.0.0.1 \
  --skip-networking=0 &
MYSQLD_PID=$!

cleanup() {
  if kill -0 "${MYSQLD_PID}" >/dev/null 2>&1; then
    mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" -uroot shutdown >/dev/null 2>&1 || true
    wait "${MYSQLD_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

MYSQL_READY=0
for _ in $(seq 1 30); do
  if mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" -uroot ping >/dev/null 2>&1; then
    MYSQL_READY=1
    break
  fi
  sleep 1
done

if [ "${MYSQL_READY}" -ne 1 ]; then
  echo "Timed out waiting for MariaDB to accept connections" >&2
  exit 1
fi

mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL

if [ ! -f "${CONFIG_FILE}" ]; then
  echo "wp-tests-config.php missing; recreating from sample" >&2
  if [ -f "${CONFIG_SAMPLE}" ]; then
    cp "${CONFIG_SAMPLE}" "${CONFIG_FILE}"
  else
    echo "Sample config not found at ${CONFIG_SAMPLE}" >&2
    exit 1
  fi
fi

sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "${CONFIG_FILE}"
sed -i "s/yourusernamehere/${DB_USER}/" "${CONFIG_FILE}"
sed -i "s/yourpasswordhere/${DB_PASSWORD}/" "${CONFIG_FILE}"
sed -i "s|localhost|${DB_HOST}|1" "${CONFIG_FILE}"
sed -i "s|dirname( __FILE__ ) . '/../../'|'${WP_CORE_DIR}/'|" "${CONFIG_FILE}"

export WP_TESTS_DIR DB_HOST DB_NAME DB_USER DB_PASSWORD

# ==== Here and below is a universal "command dispatcher" ====

WORKDIR="/srv/web"
cd "$WORKDIR"

log_section() {
  echo
  echo "========================================"
  echo ">>> $1"
  echo "========================================"
}

run_composer_install() {
  log_section "Composer install"
  if [ -f composer.json ]; then
    # Skip if vendor/ already exists.
    if [ -d vendor ]; then
      echo "vendor/ already exists, skipping composer install"
    else
      composer install --no-interaction --prefer-dist
    fi
  else
    echo "composer.json not found in ${WORKDIR}, skipping composer install"
  fi
}

run_phpunit() {
  log_section "PHP Unit tests (phpunit.xml)"
  vendor/bin/phpunit -c phpunit.xml "$@"
}

run_wpunit() {
  log_section "WordPress Unit tests (php-wp-unit.xml)"
  vendor/bin/phpunit -c php-wp-unit.xml "$@"
}

# Collect exit codes of both suites
run_all_tests() {
  local phpunit_exit=0
  local wpunit_exit=0

  run_composer_install

  run_phpunit "$@" || phpunit_exit=$?
  run_wpunit "$@" || wpunit_exit=$?

  if [ "$phpunit_exit" -ne 0 ] || [ "$wpunit_exit" -ne 0 ]; then
    echo
    echo "One or more test suites failed:"
    echo "  PHP Unit exit code: $phpunit_exit"
    echo "  WP Unit exit code:  $wpunit_exit"
    # If needed to distinguish, we could return, for example, the first non-zero
    exit 1
  fi
}

CMD="${1:-test:all}"

case "$CMD" in
  test:all)
    shift
    run_all_tests "$@"
    ;;

  test:phpunit)
    shift
    run_composer_install
    run_phpunit "$@"
    ;;

  test:wpunit)
    shift
    run_composer_install
    run_wpunit "$@"
    ;;

  composer-install)
    shift
    run_composer_install
    ;;

  *)
    # Fallback to default behavior: run the given command.
    # docker run image vendor/bin/phpunit -c phpunit.xml
    exec "$@"
    ;;
esac
