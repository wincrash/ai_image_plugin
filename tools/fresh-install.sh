#!/usr/bin/env bash
#
# Rehearse the production install: a clean WordPress, one activation, one gate.
#
#   bash tools/fresh-install.sh
#
# Ruslan's idea, before the migration (2026-08-07), and the reason is sound.
# The main testbed has been running for weeks — its tables were migrated
# forward from an older schema, its options carry leftovers from browser
# testing, its storage directories already exist with the right ownership.
# Production gets one upload and one activation on a live shop with 2500
# products and no staging copy. This is where that gets rehearsed.
#
# Destroys and rebuilds the stack every time. It takes a few minutes; that is
# the price of the answer meaning anything.
#
# WooCommerce and WC Fields Factory are copied out of the main testbed
# container rather than downloaded: WCFF 4.1.9 is not on wordpress.org, and
# production runs that exact build.

set -uo pipefail

SERVER="${AICAKE_SERVER:-ruslan@ruslan-server}"
REMOTE="/home/ruslan/wordpress-test/fresh"
MAIN="/home/ruslan/wordpress-test"
SITE="http://100.127.55.45:8081"

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

# Everything runs over one ssh hop; batching keeps it quick.
remote() { ssh "$SERVER" "$@"; }

say "1/6  deploying the clean-room compose"
remote "mkdir -p $REMOTE"
scp -q "$(dirname "$0")/../infra/fresh/docker-compose.yaml" "$SERVER:$REMOTE/docker-compose.yaml" || exit 1
scp -q "$(dirname "$0")/fresh-check.php" "$SERVER:$REMOTE/fresh-check.php" || exit 1

say "2/6  destroying anything left from a previous run"
remote "cd $REMOTE && docker compose down -v >/dev/null 2>&1; echo gone"

say "3/6  starting a clean WordPress"
remote "cd $REMOTE && docker compose up -d >/dev/null 2>&1 && sleep 15 && docker compose ps --format '{{.Service}} {{.State}}'"

say "4/6  installing WordPress, WooCommerce and WC Fields Factory"
remote "cd $REMOTE && \
	docker compose exec -T cli wp core install \
		--url=$SITE --title='AI Cake fresh install' \
		--admin_user=admin --admin_password=FreshPass123 \
		--admin_email=fresh@example.com --skip-email 2>&1 | tail -2 && \
	docker compose exec -T cli wp language core install lt_LT --activate 2>&1 | tail -1 && \
	for p in woocommerce wc-fields-factory; do \
		docker cp wordpress-test-wordpress-1:/var/www/html/wp-content/plugins/\$p /tmp/\$p >/dev/null 2>&1 && \
		docker cp /tmp/\$p \$(docker compose ps -q wordpress):/var/www/html/wp-content/plugins/ >/dev/null 2>&1 && \
		rm -rf /tmp/\$p; \
	done && \
	docker compose exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins && \
	docker compose exec -T cli wp plugin activate woocommerce wc-fields-factory 2>&1 | tail -2"

say "5/6  installing the plugin the way production will get it"
# Straight from the git working copy, not from the main testbed's deployed
# copy — production is uploaded from C:\AI_IMAGE, so that is what to rehearse.
remote "cd $REMOTE && rm -rf /tmp/ai-cake-topper"
scp -qr "$(dirname "$0")/../plugin/ai-cake-topper" "$SERVER:/tmp/ai-cake-topper" || exit 1
remote "cd $REMOTE && \
	docker cp /tmp/ai-cake-topper \$(docker compose ps -q wordpress):/var/www/html/wp-content/plugins/ >/dev/null && \
	docker compose exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/ai-cake-topper && \
	rm -rf /tmp/ai-cake-topper && \
	docker compose exec -T cli wp plugin activate ai-cake-topper 2>&1 | tail -3"

say "6/6  the gate"
# wp-cli lives in the `cli` container, so the check has to sit somewhere both
# containers can see — which is the shared /var/www/html volume.
remote "cd $REMOTE && \
	docker cp fresh-check.php \$(docker compose ps -q wordpress):/var/www/html/fresh-check.php >/dev/null && \
	docker compose exec -T wordpress chown www-data:www-data /var/www/html/fresh-check.php && \
	docker compose exec -T cli wp eval-file /var/www/html/fresh-check.php --path=/var/www/html 2>&1; \
	docker compose exec -T wordpress rm -f /var/www/html/fresh-check.php"

status=$?

say "debug.log after activation"
remote "cd $REMOTE && docker compose exec -T wordpress sh -c 'if [ -s /var/www/html/wp-content/debug.log ]; then cat /var/www/html/wp-content/debug.log; else echo \"(empty — no notices, warnings or fatals)\"; fi'"

printf '\nThe stack is left running at %s (admin / FreshPass123).\n' "$SITE"
printf 'Tear it down with:  ssh %s "cd %s && docker compose down -v"\n' "$SERVER" "$REMOTE"

exit $status
