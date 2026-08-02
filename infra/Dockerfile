# Pinned to the version the testbed is already running, so rebuilding does not
# silently upgrade WordPress underneath us mid-project.
FROM wordpress:7.0.2-php8.3-apache

# Imagick is needed for the parts of the image pipeline GD cannot do well:
#   - circle masks with clean anti-aliased edges
#   - arc / curved text along a topper's edge
#   - CMYK soft-proofing for the narrow edible-ink gamut
#   - Lanczos resampling (visibly better than GD's bicubic on flat artwork)
#
# The plugin has a GD fallback for all of it except arc text and CMYK, because
# production hosting may not have Imagick. But the testbed should have it, so
# we develop against the good path and test the fallback deliberately.
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
