#!/usr/bin/env bash
#
# Download the WordPress test suite and prepare a test database.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]
#
# Example:
#   bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1:3306 6.7 false

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -sSL "$1" > "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		exit 1
	fi
}

# Resolve the version to a tag the test suite is published under.
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\-(beta|RC)[0-9]+$ ]]; then
	WP_BRANCH=${WP_VERSION%\-*}
	WP_TESTS_TAG="branches/$WP_BRANCH"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		# x.x.0 is tagged as x.x
		WP_TESTS_TAG="tags/${WP_VERSION%??}"
	else
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG="trunk"
else
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	# `|| true` because a grep that matches nothing exits non-zero, which under
	# `set -e` with `pipefail` would abort the script rather than fall through.
	LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//' | head -1 || true)
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Could not determine the latest WordPress version." >&2
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		download https://wordpress.org/nightly-builds/wordpress-latest.zip /tmp/wordpress-nightly.zip

		# Extracted somewhere distinct on purpose: the archive unpacks to a directory
		# called "wordpress", which collides with the default WP_CORE_DIR of
		# /tmp/wordpress and would otherwise mean moving a directory into itself.
		local extract_dir='/tmp/wp-nightly-extract'
		rm -rf "$extract_dir"
		mkdir -p "$extract_dir"

		unzip -q /tmp/wordpress-nightly.zip -d "$extract_dir"
		mv "$extract_dir"/wordpress/* "$WP_CORE_DIR"
		rm -rf "$extract_dir"

		return
	fi

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
		download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
		# The version-check API reports the current release, so this grep finds nothing
		# whenever an older branch was requested. That is expected, not an error --
		# hence `|| true`, and the fallback to the requested version below.
		LATEST_VERSION=$(grep -o "\"version\":\"${WP_VERSION}[^\"]*" /tmp/wp-latest.json | sed 's/"version":"//' | head -1 || true)
		ARCHIVE_NAME="wordpress-${LATEST_VERSION:-$WP_VERSION}"
	else
		ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	if [ ! -d "$WP_TESTS_DIR" ]; then
		mkdir -p "$WP_TESTS_DIR"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
		svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		return
	fi

	download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

	# The sed -i flag differs between GNU and BSD.
	if [[ $(uname -s) == 'Darwin' ]]; then
		local ioption='-i.bak'
	else
		local ioption='-i'
	fi

	local core_dir_escaped
	core_dir_escaped=$(echo "$WP_CORE_DIR" | sed 's/[\/&]/\\&/g')

	sed $ioption "s:dirname( __FILE__ ) . '/src/':'${core_dir_escaped}':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi

	local extra=""
	local host=$DB_HOST
	local port

	if [[ $DB_HOST == *":"* ]]; then
		host=${DB_HOST%:*}
		port=${DB_HOST##*:}
		if [[ $port =~ ^[0-9]+$ ]]; then
			extra=" --host=$host --port=$port --protocol=tcp"
		else
			extra=" --socket=$port"
		fi
	else
		extra=" --host=$host --protocol=tcp"
	fi

	# shellcheck disable=SC2086
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$extra
}

install_wp
install_test_suite
create_db

echo "WordPress test suite ready."
echo "  core:  $WP_CORE_DIR"
echo "  tests: $WP_TESTS_DIR"
