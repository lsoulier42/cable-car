# Image épinglée pour des builds reproductibles : https://hub.docker.com/r/dunglas/frankenphp/tags
# Tag par défaut = Debian Trixie (variante recommandée par les mainteneurs).
FROM dunglas/frankenphp:1.12.7-php8.5

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Même jeu d'extensions que l'ancienne image FPM, sans xdebug, + opcache et Composer.
RUN install-php-extensions pgsql pdo_pgsql intl zip apcu mbstring sodium opcache @composer

# Configuration PHP applicative.
COPY ./conf.d/10-app.ini $PHP_INI_DIR/conf.d/10-app.ini

# Configuration Caddy/FrankenPHP (surchargée en dev par le mount compose).
COPY ./Caddyfile /etc/frankenphp/Caddyfile

ENV WORKDIR=/var/www/html
WORKDIR $WORKDIR

# Utilisateur non-root aligné sur l'utilisateur hôte (évite les conflits de permissions sur les volumes).
ARG UID=1000
ARG GID=1000
RUN set -eux; \
    if ! getent group "${GID}" > /dev/null; then groupadd --gid "${GID}" docker; fi; \
    if ! getent passwd "${UID}" > /dev/null; then useradd --create-home --uid "${UID}" --gid "${GID}" --shell /bin/bash docker; fi; \
    mkdir -p /data/caddy /config/caddy; \
    chown -R "${UID}:${GID}" /data /config

EXPOSE 8080

USER docker

# Entrypoint officiel PHP : `docker compose run php bash` et `php bin/console …` restent
# utilisables tels quels (le CMD lance le serveur FrankenPHP).
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
