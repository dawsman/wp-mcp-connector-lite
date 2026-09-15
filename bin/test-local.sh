#!/usr/bin/env bash
#
# Run the PHPUnit suite locally against a throwaway MySQL container.
#
#   bin/test-local.sh            # set up if needed, then run the suite
#   bin/test-local.sh --fresh    # recreate the database container first
#   bin/test-local.sh --teardown # stop and remove the container, then exit
#
# The WordPress test library is installed OUTSIDE the repo (see CACHE_DIR
# below), because the test bootstrap bakes an absolute ABSPATH into
# wp-tests-config.php — a copy checked out inside the repo goes stale the
# moment the repo moves, and tests/wordpress{,-tests-lib}/ are gitignored
# leftovers from exactly that mistake. Do not use them.
#
# Requires: docker (OrbStack or Docker Desktop), php, composer, curl.

set -euo pipefail

cd "$(dirname "$0")/.."

CONTAINER=${CONTAINER:-wpmcp-test-mysql}
PORT=${PORT:-33306}
DB_NAME=${DB_NAME:-wp_test}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-root}
WP_VERSION=${WP_VERSION:-latest}
CACHE_DIR=${CACHE_DIR:-$HOME/.cache/wp-mcp-connect-tests}

export WP_TESTS_DIR="$CACHE_DIR/wordpress-tests-lib"
export WP_CORE_DIR="$CACHE_DIR/wordpress"

teardown() {
	echo "==> Removing $CONTAINER"
	docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
}

case "${1:-}" in
	--teardown)
		teardown
		exit 0
		;;
	--fresh)
		teardown
		rm -rf "$CACHE_DIR"
		shift
		;;
esac

if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
	echo "==> Starting MySQL on 127.0.0.1:$PORT"
	docker run -d --name "$CONTAINER" \
		-e MYSQL_ROOT_PASSWORD="$DB_PASS" \
		-e MYSQL_DATABASE="$DB_NAME" \
		-p "$PORT:3306" \
		mysql:8.0 --default-authentication-plugin=mysql_native_password >/dev/null
elif [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" != "true" ]; then
	echo "==> Restarting $CONTAINER"
	docker start "$CONTAINER" >/dev/null
fi

echo "==> Waiting for MySQL"
for _ in $(seq 1 60); do
	if docker exec "$CONTAINER" mysqladmin ping -uroot -p"$DB_PASS" --silent >/dev/null 2>&1; then
		break
	fi
	sleep 1
done

if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
	echo "==> Installing the WordPress test library into $CACHE_DIR"
	bash bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "127.0.0.1:$PORT" "$WP_VERSION"
fi

if [ ! -f vendor/bin/phpunit ]; then
	echo "==> composer install"
	composer install --no-interaction --prefer-dist
fi

echo "==> php -l"
php -l wp-mcp-connect.php >/dev/null
php -l uninstall.php >/dev/null
for f in includes/*.php; do php -l "$f" >/dev/null; done

echo "==> phpunit"
vendor/bin/phpunit "$@"

echo
echo "Done. Stop the database with: bin/test-local.sh --teardown"
