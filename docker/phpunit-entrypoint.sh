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
RAMSEY_VERSION="${RAMSEY_VERSION:-}"
CONFIG_SAMPLE="${WP_DEVELOP_DIR}/wp-tests-config-sample.php"
CONFIG_FILE="${WP_DEVELOP_DIR}/wp-tests-config.php"

MYSQL_RUNTIME_USER="${MYSQL_RUNTIME_USER:-$(id -un 2>/dev/null || echo root)}"
MYSQL_RUN_DIR="$(dirname "${MYSQL_SOCKET}")"
MYSQLD_PID=""
WP_ENV_READY=0

WORKDIR="/srv/web"

cleanup() {
  if [ -n "${MYSQLD_PID}" ] && kill -0 "${MYSQLD_PID}" >/dev/null 2>&1; then
    mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" -uroot shutdown >/dev/null 2>&1 || true
    wait "${MYSQLD_PID}" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

cd "$WORKDIR"
git config --global --add safe.directory "*" >/dev/null 2>&1 || true

log_section() {
  echo
  echo "========================================"
  echo ">>> $1"
  echo "========================================"
}

start_database() {
  log_section "MariaDB"

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

  local mysql_ready=0
  for _ in $(seq 1 30); do
    if mysqladmin --protocol=socket --socket="${MYSQL_SOCKET}" -uroot ping >/dev/null 2>&1; then
      mysql_ready=1
      break
    fi
    sleep 1
  done

  if [ "${mysql_ready}" -ne 1 ]; then
    echo "Timed out waiting for MariaDB to accept connections" >&2
    exit 1
  fi

  mysql --protocol=socket --socket="${MYSQL_SOCKET}" -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
}

prepare_wp_tests() {
  if [ "${WP_ENV_READY}" -eq 1 ]; then
    return
  fi

  start_database

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
  WP_ENV_READY=1
}

run_composer_install() {
  log_section "Composer dependencies"
  if [ -f composer.json ]; then
    if [ -n "${RAMSEY_VERSION}" ]; then
      composer update ramsey/collection \
        --with "ramsey/collection:${RAMSEY_VERSION}" \
        --prefer-dist \
        --no-interaction
    else
      # Composer install is idempotent: it synchronizes a bind-mounted vendor/
      # with composer.lock without changing the resolved dependency versions.
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

run_wp_integration() {
  log_section "WP Integration tests (php-wp-unit.xml)"
  prepare_wp_tests
  vendor/bin/phpunit -c php-wp-unit.xml "$@"
}

run_phpcs() {
  log_section "PHP CodeSniffer"
  composer run phpcs
}

run_coverage() {
  local coverage_dir="build/coverage"
  local clover_report="${coverage_dir}/clover.xml"
  local text_report="${coverage_dir}/coverage.txt"
  local json_summary="${coverage_dir}/coverage-summary.json"
  local markdown_summary="${coverage_dir}/coverage-summary.md"
  local phpunit_exit=0
  local gate_exit=0

  mkdir -p "${coverage_dir}"
  rm -f "${clover_report}" "${text_report}" "${json_summary}" "${markdown_summary}"

  run_composer_install
  prepare_wp_tests

  log_section "Combined unit and WordPress integration coverage"
  phpdbg -qrr vendor/bin/phpunit \
    -c phpunit-coverage.xml \
    --coverage-clover "${clover_report}" \
    --coverage-text="${text_report}" \
    --colors=never \
    "$@" || phpunit_exit=$?

  php docker/check-coverage.php \
    "${clover_report}" \
    coverage-baseline.json \
    "${json_summary}" \
    "${markdown_summary}" || gate_exit=$?

  if [ "${phpunit_exit}" -ne 0 ]; then
    echo "Combined PHPUnit coverage run failed with exit code ${phpunit_exit}" >&2
    return "${phpunit_exit}"
  fi

  return "${gate_exit}"
}

run_all_tests() {
  local phpunit_exit=0
  local wpunit_exit=0

  run_composer_install

  run_phpunit "$@" || phpunit_exit=$?
  run_wp_integration "$@" || wpunit_exit=$?

  if [ "$phpunit_exit" -ne 0 ] || [ "$wpunit_exit" -ne 0 ]; then
    echo
    echo "One or more test suites failed:"
    echo "  PHP Unit exit code: $phpunit_exit"
    echo "  WP Integration exit code: $wpunit_exit"
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

  test:integration|test:wp-integration|test:wpunit)
    shift
    run_composer_install
    run_wp_integration "$@"
    ;;

  test:coverage)
    shift
    run_coverage "$@"
    ;;

  cs:phpcs|phpcs)
    shift
    run_composer_install
    run_phpcs
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
