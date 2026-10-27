#!/usr/bin/env bash
# Runs the unit tests in Docker, on the oldest and newest PHP versions the plugin supports.
# Usage: bin/test.sh [phpunit args...]   (PHP=7.4 bin/test.sh to run one version only)
set -euo pipefail
cd "$(dirname "$0")/.."
export MSYS_NO_PATHCONV=1
root="$(pwd -W 2>/dev/null || pwd)"
image_for() {
	# The 8.3 image is built with the bz2 extension so bzip2-compressed backups are covered.
	if [ "$1" = "8.3" ]; then
		docker build -q -t wpress-restore-php:8.3 -f dev/php-test.Dockerfile dev >/dev/null
		echo wpress-restore-php:8.3
	else
		echo "php:$1-cli"
	fi
}
for v in ${PHP:-7.4 8.3}; do
	echo "== PHP $v"
	docker run --rm -v "$root:/app" -w /app "$(image_for "$v")" php -d memory_limit=512M tools/phpunit.phar -c tests/phpunit.xml "$@"
done
