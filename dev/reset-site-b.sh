#!/usr/bin/env bash
# Rebuilds site B from scratch (MariaDB, prefix wpb_, admin bob/pass-b) with the freshly built plugin.
# Usage: dev/reset-site-b.sh
set -euo pipefail
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
python ../bin/build.py >/dev/null
docker compose rm -sf site-b cli-b db-b >/dev/null 2>&1
docker volume rm -f wpress-restore-dev_html-b wpress-restore-dev_db-b >/dev/null
docker compose up -d site-b cli-b db-b >/dev/null 2>&1
for i in $(seq 1 30); do
	if docker compose exec -T cli-b test -f /var/www/html/wp-config.php 2>/dev/null \
		&& docker compose exec -T cli-b wp db check >/dev/null 2>&1; then
		break
	fi
	sleep 3
done
docker compose exec -T cli-b wp core install --url=http://localhost:8082 --title="Site B" --admin_user=bob --admin_password=pass-b --admin_email=bob@site-b.test --skip-email >/dev/null
docker compose cp ../dist/wpress-restore.zip cli-b:/tmp/wpress-restore.zip >/dev/null 2>&1
docker compose exec -T -u 0 cli-b chown 33:33 /tmp/wpress-restore.zip
docker compose exec -T cli-b wp plugin install /tmp/wpress-restore.zip --activate >/dev/null
echo "site B reset: $(docker compose exec -T cli-b wp option get blogname), plugin $(docker compose exec -T cli-b wp plugin get wpress-restore --field=version)"
