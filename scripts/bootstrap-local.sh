#!/usr/bin/env bash
# Prepare a bare local WordPress for developing this plugin.
#
# Installs WordPress, activates this plugin, and enables pretty permalinks so
# path-based scan targets resolve. That is all — no theme, no seeded content.
#
# The site this plugin ships into belongs to somebody else. Its content is not
# this repository's business, and pretending otherwise here would make the
# local environment a worse model of production, not a better one.
#
# Usage:
#   docker compose run --rm wpcli bash /scripts/bootstrap-local.sh

set -euo pipefail

WP_PATH=/var/www/html
WP="wp --path=$WP_PATH"

# Probed in PHP rather than with `wp db check`, which shells out to mysqlcheck —
# absent from some WordPress images, and when it is missing the check fails
# forever rather than reporting the database is down.
wait_for_db() {
	attempt=0
	while [ "$attempt" -lt 60 ]; do
		if php -r '
			$host = getenv("WORDPRESS_DB_HOST") ?: "db:3306";
			$parts = explode(":", $host);
			$c = @mysqli_connect($parts[0], getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), isset($parts[1]) ? (int) $parts[1] : 3306);
			exit($c ? 0 : 1);
		' >/dev/null 2>&1; then
			return 0
		fi
		echo "waiting for database..."
		sleep 2
		attempt=$((attempt + 1))
	done
	echo "ERROR: database was not reachable after 120s" >&2
	return 1
}

wait_for_db

if ! $WP core is-installed; then
	$WP core install \
		--url="$WORDPRESS_BASE_URL" \
		--title="Plugin development site" \
		--admin_user="$WP_ADMIN_USER" \
		--admin_password="$WP_ADMIN_PASSWORD" \
		--admin_email="$WP_ADMIN_EMAIL" \
		--skip-email
fi

if ! $WP plugin is-active wp-notice-signup; then
	$WP plugin activate wp-notice-signup
fi

# Required before any path-based scan target resolves.
$WP rewrite structure "/%postname%/"
$WP rewrite flush --hard

# --- make the fixture itself clean -----------------------------------------
# Twenty Twenty-Five's Navigation block falls back to a Page List when no menu
# exists, which renders a <ul> directly inside a <ul>. axe reports that as
# `list` (serious) on every page of the site.
#
# It is the theme's markup, not this plugin's — and that is exactly why it has
# to be dealt with here. A local fixture that starts red teaches the developer
# to ignore red, and then the gate is worthless. The baseline must be clean so
# that anything appearing later was caused by the change under test.
#
# On a real host site you cannot fix somebody else's theme, and that is a
# genuine problem rather than an oversight: see docs/how-the-gate-works.md,
# "findings you did not cause".
nav_id=$($WP post list --post_type=wp_navigation --field=ID --posts_per_page=1)

if [ -n "$nav_id" ]; then
	nav_content=""
	for slug in $($WP post list --post_type=page --field=post_name --posts_per_page=20); do
		page_id=$($WP post list --post_type=page --name="$slug" --field=ID --posts_per_page=1)
		[ -z "$page_id" ] && continue
		title=$($WP post get "$page_id" --field=post_title)
		url=$($WP post get "$page_id" --field=url)
		nav_content="$nav_content<!-- wp:navigation-link {\"label\":\"$title\",\"type\":\"page\",\"id\":$page_id,\"url\":\"$url\",\"kind\":\"post-type\"} /-->"
	done

	# A site with no pages at all still needs the fallback replaced, or the
	# empty Page List renders the same nested list.
	if [ -z "$nav_content" ]; then
		nav_content="<!-- wp:navigation-link {\"label\":\"Home\",\"type\":\"custom\",\"url\":\"$WORDPRESS_BASE_URL/\",\"kind\":\"custom\"} /-->"
	fi

	$WP post update "$nav_id" --post_content="$nav_content" >/dev/null
	echo "  navigation: replaced the theme's page-list fallback"
fi

echo "ready: $($WP option get blogname) at $WORDPRESS_BASE_URL"
