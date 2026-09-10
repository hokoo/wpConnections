#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_NAME="${DB_NAME:-wordpress_test}"
DB_USER="${DB_USER:-wordpress}"
DB_PASSWORD="${DB_PASSWORD:-wordpress}"
WP_VERSION="${WP_VERSION:-unknown}"
WP_DEVELOP_DIR="${WP_DEVELOP_DIR:-/opt/wordpress-develop}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/opt/wordpress-develop/tests/phpunit}"
MYSQL_SOCKET="${MYSQL_SOCKET:-/run/mysqld/mysqld.sock}"
MYSQL_DATA_DIR="${MYSQL_DATA_DIR:-/tmp/mysql-data}"
RAMSEY_VERSION="${RAMSEY_VERSION:-}"
EXPECTED_PHP_VERSION="${EXPECTED_PHP_VERSION:-}"
EXPECTED_WP_VERSION="${EXPECTED_WP_VERSION:-}"
TEST_RANDOM_SEED="${TEST_RANDOM_SEED:-}"
IMAGE_BUILD_MANIFEST="${IMAGE_BUILD_MANIFEST:-/usr/local/share/wpconnections-test-image/build-manifest}"
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

get_wordpress_runtime_version() {
  local wordpress_version_file="${WP_DEVELOP_DIR}/src/wp-includes/version.php"

  if [ ! -f "${wordpress_version_file}" ]; then
    printf 'unknown'
    return
  fi

  php -r 'include $argv[1]; echo isset($wp_version) ? $wp_version : "unknown";' \
    "${wordpress_version_file}"
}

fail_image_freshness_check() {
  echo "Test image freshness check failed: $1" >&2
  echo "Run 'make tests.build' to rebuild with cache, or 'make tests.clean' for a no-cache rebuild and full verification." >&2
  return 78
}

validate_test_image() {
  local manifest_schema=""
  local manifest_dockerfile_sha256=""
  local manifest_entrypoint_sha256=""
  local manifest_php_version=""
  local manifest_wp_version=""
  local current_dockerfile_sha256=""
  local current_entrypoint_sha256=""
  local php_runtime_version=""
  local wordpress_runtime_version=""
  local wordpress_comparable_version=""
  local key=""
  local value=""

  # Direct Docker/CI invocations do not opt into the local Compose freshness
  # contract. Their images are built immediately before use by the workflows.
  if [ -z "${EXPECTED_PHP_VERSION}" ] && [ -z "${EXPECTED_WP_VERSION}" ]; then
    return
  fi

  if [ -z "${EXPECTED_PHP_VERSION}" ] || [ -z "${EXPECTED_WP_VERSION}" ]; then
    fail_image_freshness_check \
      "EXPECTED_PHP_VERSION and EXPECTED_WP_VERSION must either both be set or both be unset."
    return
  fi

  if [ ! -f "${IMAGE_BUILD_MANIFEST}" ]; then
    fail_image_freshness_check \
      "the baked build manifest is missing, so this image predates freshness validation."
    return
  fi

  while IFS='=' read -r key value; do
    case "${key}" in
      schema) manifest_schema="${value}" ;;
      dockerfile_sha256) manifest_dockerfile_sha256="${value}" ;;
      entrypoint_sha256) manifest_entrypoint_sha256="${value}" ;;
      php_version) manifest_php_version="${value}" ;;
      wp_version) manifest_wp_version="${value}" ;;
    esac
  done < "${IMAGE_BUILD_MANIFEST}"

  if [ "${manifest_schema}" != "1" ] || \
    [ -z "${manifest_dockerfile_sha256}" ] || \
    [ -z "${manifest_entrypoint_sha256}" ] || \
    [ -z "${manifest_php_version}" ] || \
    [ -z "${manifest_wp_version}" ]; then
    fail_image_freshness_check \
      "the baked build manifest is incomplete or uses an unsupported schema."
    return
  fi

  if [ ! -f "${WORKDIR}/Dockerfile.phpunit" ] || \
    [ ! -f "${WORKDIR}/docker/phpunit-entrypoint.sh" ]; then
    fail_image_freshness_check \
      "current Dockerfile.phpunit or docker/phpunit-entrypoint.sh is not visible in ${WORKDIR}."
    return
  fi

  current_dockerfile_sha256="$(sha256sum "${WORKDIR}/Dockerfile.phpunit" | cut -d ' ' -f 1)"
  current_entrypoint_sha256="$(sha256sum "${WORKDIR}/docker/phpunit-entrypoint.sh" | cut -d ' ' -f 1)"

  if [ "${current_dockerfile_sha256}" != "${manifest_dockerfile_sha256}" ]; then
    fail_image_freshness_check \
      "Dockerfile.phpunit differs from the version baked into the image."
    return
  fi

  if [ "${current_entrypoint_sha256}" != "${manifest_entrypoint_sha256}" ]; then
    fail_image_freshness_check \
      "docker/phpunit-entrypoint.sh differs from the version baked into the image."
    return
  fi

  if [ "${EXPECTED_PHP_VERSION}" != "${manifest_php_version}" ]; then
    fail_image_freshness_check \
      "expected PHP ${EXPECTED_PHP_VERSION}, but the image was built for ${manifest_php_version}."
    return
  fi

  if [ "${EXPECTED_WP_VERSION}" != "${manifest_wp_version}" ] || \
    [ "${EXPECTED_WP_VERSION}" != "${WP_VERSION}" ]; then
    fail_image_freshness_check \
      "expected WordPress ${EXPECTED_WP_VERSION}, but the image request is ${manifest_wp_version} (runtime environment: ${WP_VERSION})."
    return
  fi

  php_runtime_version="$(php -r 'echo PHP_VERSION;')"
  if [[ "${EXPECTED_PHP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    if [[ "${php_runtime_version}" != "${EXPECTED_PHP_VERSION}."* ]]; then
      fail_image_freshness_check \
        "expected PHP ${EXPECTED_PHP_VERSION}.x, but the runtime is ${php_runtime_version}."
      return
    fi
  elif [ "${php_runtime_version}" != "${EXPECTED_PHP_VERSION}" ]; then
    fail_image_freshness_check \
      "expected PHP ${EXPECTED_PHP_VERSION}, but the runtime is ${php_runtime_version}."
    return
  fi

  wordpress_runtime_version="$(get_wordpress_runtime_version)"
  if [ "${wordpress_runtime_version}" = "unknown" ]; then
    fail_image_freshness_check \
      "the WordPress runtime version cannot be read from the image."
    return
  fi

  # wordpress-develop release tags report an intentional "-src" suffix.
  # Strip only that known build-tree marker before comparing release numbers.
  wordpress_comparable_version="${wordpress_runtime_version%-src}"

  if [ "${EXPECTED_WP_VERSION}" != "trunk" ] && \
    [ "${wordpress_comparable_version}" != "${EXPECTED_WP_VERSION}" ] && \
    { [ "${EXPECTED_WP_VERSION}" != "${wordpress_comparable_version}.0" ]; }; then
    fail_image_freshness_check \
      "expected WordPress ${EXPECTED_WP_VERSION}, but the runtime is ${wordpress_runtime_version}."
    return
  fi
}

log_runtime_versions() {
  local wordpress_runtime_version=""
  local ramsey_runtime_version="unknown"

  wordpress_runtime_version="$(get_wordpress_runtime_version)"

  if [ -f vendor/composer/installed.php ]; then
    ramsey_runtime_version="$(
      php -r '$installed = require $argv[1]; echo $installed["versions"]["ramsey/collection"]["pretty_version"] ?? "unknown";' \
        vendor/composer/installed.php
    )"
  fi

  log_section "Runtime versions"
  echo "PHP runtime: $(php -r 'echo PHP_VERSION;')"
  echo "WordPress requested ref: ${WP_VERSION}"
  echo "WordPress runtime: ${wordpress_runtime_version}"
  echo "Ramsey Collection runtime: ${ramsey_runtime_version}"
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

  log_runtime_versions
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

run_exception_policy() {
  local profile="$1"

  log_section "Test exception policy (${profile} profile)"
  php docker/check-test-exceptions.php \
    "--profile=${profile}" \
    test-quality-exceptions.json
}

run_coverage() {
  local profile="$1"
  shift
  local coverage_dir="build/coverage"
  local clover_report="${coverage_dir}/clover.xml"
  local text_report="${coverage_dir}/coverage.txt"
  local json_summary="${coverage_dir}/coverage-summary.json"
  local markdown_summary="${coverage_dir}/coverage-summary.md"
  local phpunit_exit=0
  local gate_exit=0

  mkdir -p "${coverage_dir}"
  rm -f "${clover_report}" "${text_report}" "${json_summary}" "${markdown_summary}"

  run_exception_policy "${profile}"
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
    "--profile=${profile}" \
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

run_quality_tool_tests() {
  log_section "Coverage policy synthetic tests"
  bash docker/tests/check-coverage.sh

  log_section "Test exception policy synthetic tests"
  bash docker/tests/check-test-exceptions.sh

  run_composer_install

  log_section "Isolation runner synthetic probe"
  bash docker/tests/check-isolation-runner.sh
}

run_isolation() {
  local random_seed="${TEST_RANDOM_SEED}"

  if [ -z "${random_seed}" ]; then
    random_seed="$(php -r 'echo random_int(1, 2147483647);')"
  fi

  if [[ ! "${random_seed}" =~ ^[0-9]+$ ]]; then
    echo "TEST_RANDOM_SEED must be a non-negative integer; got ${random_seed}" >&2
    return 2
  fi

  log_section "Isolation verification"
  echo "Reusable random seed: ${random_seed}"
  echo "Reproduce locally: make tests.isolation ISOLATION_SEED=${random_seed}"

  run_composer_install
  bash docker/run-isolation.sh "unit" phpunit.xml "${random_seed}" "$@"

  prepare_wp_tests
  bash docker/run-isolation.sh "WordPress-integration" php-wp-unit.xml "${random_seed}" "$@"
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

validate_test_image

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
    run_coverage pr "$@"
    ;;

  test:coverage:rc)
    shift
    run_coverage rc "$@"
    ;;

  test:quality-tools)
    shift
    run_quality_tool_tests "$@"
    ;;

  test:isolation)
    shift
    run_isolation "$@"
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
