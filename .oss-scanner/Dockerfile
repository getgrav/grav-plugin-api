# Grav API plugin (getgrav/grav-plugin-api), for Anthropic's OSS Scanner (https://github.com/anthropics/oss-scanner).
#
# /src                         the plugin checkout (develop)
# /var/www/grav                a Grav site built from the latest "grav-admin" download (core plus api, admin2, login,
#                              form, email and flex-objects), the way most sites start
# /var/www/grav/user/plugins/api   a symlink to /src, so the site runs this checkout and a patch made in /src is live on
#                              the next request
#
# Apache serves the site with .htaccess enabled. Start it with `grav-start`; the site listens on http://127.0.0.1/,
# the API on http://127.0.0.1/api/v1/ and the admin2 SPA on http://127.0.0.1/admin.
FROM php:8.4-apache

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates git unzip curl procps rsync \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip intl exif \
    && pecl install apcu && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Production-like PHP: errors are logged, not shown. OPcache picks up edited files immediately so a patch can be
# checked straight away.
RUN { echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=64M'; \
      echo 'post_max_size=64M'; \
      echo 'error_reporting=E_ALL'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/oss-scanner.ini

RUN a2enmod rewrite headers expires \
    && sed -ri 's!/var/www/html!/var/www/grav!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/grav>\n  Options -MultiViews +FollowSymLinks\n  AllowOverride All\n  Require all granted\n</Directory>\nServerName 127.0.0.1\n' \
         > /etc/apache2/conf-available/grav.conf \
    && a2enconf grav

# grav-start / grav-stop start and stop Apache (there is no init system in the container). grav-cli runs a Grav CLI
# command in the site as www-data, the user Apache runs as:  grav-cli bin/grav clear
# mail-catcher stands in for sendmail: every email the site sends (password resets, invitations) is written to
# /var/mail-catcher/<timestamp>.eml instead.
RUN printf '%s\n' '#!/bin/sh' 'pgrep -x apache2 >/dev/null || apachectl start' > /usr/local/bin/grav-start \
    && printf '%s\n' '#!/bin/sh' 'apachectl stop 2>/dev/null; true' > /usr/local/bin/grav-stop \
    && printf '%s\n' '#!/bin/sh' 'cd /var/www/grav && exec setpriv --reuid=www-data --regid=www-data --init-groups env HOME=/tmp php "$@"' \
         > /usr/local/bin/grav-cli \
    && printf '%s\n' '#!/bin/sh' 'cat > "/var/mail-catcher/$(date +%s%N).eml"' > /usr/local/bin/mail-catcher \
    && mkdir -p /var/mail-catcher && chmod 1777 /var/mail-catcher \
    && chmod +x /usr/local/bin/grav-start /usr/local/bin/grav-stop /usr/local/bin/grav-cli /usr/local/bin/mail-catcher

# scanner contract: the plugin checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# The site, from the current grav-admin release, with its bundled api plugin swapped for this checkout. The plugin
# commits its runtime vendor/, so nothing is installed into /src.
RUN curl -fsSL -o /tmp/grav-admin.zip https://getgrav.org/download/core/grav-admin/latest \
    && unzip -q /tmp/grav-admin.zip -d /opt \
    && mv /opt/grav-admin /var/www/grav \
    && rm /tmp/grav-admin.zip \
    && rm -rf /var/www/grav/user/plugins/api \
    && ln -s /src /var/www/grav/user/plugins/api \
    && mkdir -p /var/www/grav/user/config/plugins \
    && printf '%s\n' 'enabled: true' 'from: site@example.com' 'to: admin@example.com' 'mailer:' '  engine: sendmail' \
         '  sendmail:' "    bin: '/usr/local/bin/mail-catcher -t'" > /var/www/grav/user/config/plugins/email.yaml

# Three accounts for access-control reproductions:
#   admin  / Admin-pass-1    super user: admin2 and the API with every permission
#   editor / Editor-pass-1   publisher: API access with pages and media read/write only, no config, users or system
#   member / Member-pass-1   front-end login only, no admin or API access
RUN php -r ' \
      $accounts = [ \
        "admin"  => ["Admin-pass-1",  "Site Admin",  "access:\n  admin:\n    login: true\n    super: true\n  api:\n    access: true\n    super: true\n  site:\n    login: true\n"], \
        "editor" => ["Editor-pass-1", "Page Editor", "access:\n  api:\n    access: true\n    pages:\n      read: true\n      write: true\n    media:\n      read: true\n      write: true\n  site:\n    login: true\n"], \
        "member" => ["Member-pass-1", "Site Member", "access:\n  site:\n    login: true\n"], \
      ]; \
      foreach ($accounts as $user => [$password, $name, $access]) { \
        file_put_contents("/var/www/grav/user/accounts/$user.yaml", \
          "state: enabled\nemail: $user@example.com\nfullname: \x27$name\x27\nlanguage: en\n" . $access \
          . "hashed_password: " . password_hash($password, PASSWORD_DEFAULT) . "\n"); \
      }' \
    && chown -R www-data:www-data /var/www/grav /src

# The plugin's PHPUnit suite needs its dev dependencies, which the committed vendor/ leaves out. They live in a copy
# at /opt/api-dev so /src stays clean; `api-phpunit` syncs /src into that copy first, so it always tests the current
# checkout, then runs PHPUnit against the site's core:  api-phpunit    api-phpunit --filter PermissionResolverTest
RUN rsync -a --exclude .git --exclude vendor /src/ /opt/api-dev/ \
    && cd /opt/api-dev && composer install --no-progress \
    && chown -R www-data:www-data /opt/api-dev \
    && printf '%s\n' '#!/bin/sh' 'set -e' \
         'rsync -a --delete --exclude .git --exclude vendor --exclude .phpunit.cache /src/ /opt/api-dev/' \
         'chown -R www-data:www-data /opt/api-dev' \
         'cd /opt/api-dev && exec setpriv --reuid=www-data --regid=www-data --init-groups env HOME=/tmp GRAV_ROOT=/var/www/grav php vendor/bin/phpunit "$@"' \
         > /usr/local/bin/api-phpunit \
    && chmod +x /usr/local/bin/api-phpunit

# Smoke-test the site, the admin, an anonymous API route, a token login and one deny rule, and run the unit suite,
# without letting a failing test abort the build.
RUN grav-start \
    && curl -fsS -o /dev/null http://127.0.0.1/ \
    && curl -fsS -o /dev/null http://127.0.0.1/admin \
    && curl -fsS -o /dev/null http://127.0.0.1/api/v1/auth/password-policy \
    && curl -fsS -o /dev/null -X POST -H 'Content-Type: application/json' \
         -d '{"username":"admin","password":"Admin-pass-1"}' http://127.0.0.1/api/v1/auth/token \
    && test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/user/accounts/admin.yaml)" = 403 \
    && grav-stop \
    && (api-phpunit --no-progress || echo "WARNING: api unit tests reported failures")

# /src belongs to www-data so the site can write through it; let root's git work in it too. Keep build products out
# of the checkout's `git status`.
RUN git config --system --add safe.directory '*' \
    && (printf '.oss-scanner/\n.phpunit.cache/\n' >> /src/.git/info/exclude || true)

WORKDIR /src
