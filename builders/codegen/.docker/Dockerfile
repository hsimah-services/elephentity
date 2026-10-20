# Build-time toolchain for Elephentity: PHPStan, PHPUnit, PHP-CS-Fixer and the eleph CLI.
#
# There is deliberately no service here. Nothing in this image runs as a daemon, so
# containers are created per command and removed on exit. When the WordPress adaptor
# needs real integration tests, MariaDB and WordPress arrive as a compose file
# alongside this — those are the things you would start and stop.
# Image names are fully qualified: podman refuses to guess a registry for an
# unqualified name and prompts interactively, which would break unattended builds.
FROM docker.io/library/php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libzip-dev \
    # mbstring and zip are not in the base image; PHPUnit needs the former,
    # Composer wants the latter to avoid shelling out to unzip.
    && docker-php-ext-install -j"$(nproc)" mbstring zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=docker.io/library/composer:2 /usr/bin/composer /usr/bin/composer

# Composer refuses to run as root without this, and rootless podman maps the host
# user to root inside the container.
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
