# Pinned to the version the testbed is already running, so rebuilding does not
# silently upgrade WordPress underneath us mid-project.
FROM wordpress:7.0.2-php8.3-apache

# Imagick is kept here as a REFERENCE ENGINE ONLY.
#
# This comment used to say we develop against Imagick and treat GD as the
# fallback. That was reversed by D-013/D-015: production is a managed platform
# with GD only and no way to add system packages, so GD is the real target and
# AICAKE_FORCE_GD defaults on. Development runs the path the customer runs.
#
# Imagick stays installed for one purpose: measuring what a host *with* it
# would gain (Lanczos vs GD bicubic, CMYK soft-proofing). Never a dependency.
#
# Installed only if absent — the official image ships it in some builds.
RUN set -eux; \
    if ! php -m | grep -qi '^imagick$'; then \
        apt-get update; \
        apt-get install -y --no-install-recommends libmagickwand-dev; \
        pecl install imagick; \
        docker-php-ext-enable imagick; \
        rm -rf /var/lib/apt/lists/*; \
    fi; \
    echo "--- PHP image extensions ---"; \
    php -m | grep -iE '^(gd|imagick|exif)$' || true

# wp-cli. Activating the plugin, inspecting the schema and running smoke tests
# all go through it, and installing it by hand into a running container is lost
# the moment the container is recreated.
RUN set -eux; \
    curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
        -o /usr/local/bin/wp; \
    chmod +x /usr/local/bin/wp; \
    wp --allow-root --version

# DO NOT add default-mysql-client here. It was tried and reverted.
#
# Debian now ships the MariaDB 11.8 client, which requires TLS by default,
# while the server is pinned to MariaDB 10.11 with no TLS configured. Every
# `wp db` command then dies with:
#
#   ERROR 2026 (HY000): TLS/SSL error: SSL is required, but the server does
#   not support it
#
# which reads like a plugin bug rather than a missing flag. `--skip-ssl` fixes
# a direct mysql invocation and a [client] entry in /etc/mysql/conf.d fixes it
# too, but neither reaches wp-cli: its pre-flight "get current SQL modes" query
# hardcodes --no-defaults and ignores passthrough flags.
#
# Without the client, `wp db query` simply says the binary is missing, which is
# honest. Use $wpdb for schema work instead:
#
#   wp --allow-root eval 'global $wpdb; print_r( $wpdb->get_results( "DESCRIBE {$wpdb->prefix}aicake_designs" ) );'
