# syntax=docker/dockerfile:1.7
#
# One image that runs the whole TDS API surface: the Slim gateway on :8000
# plus the four micro-backends on their loopback ports, supervised together.
#
# The gateway repo is the default build context. The four service repos are
# pulled in as *named build contexts* (BuildKit) so a single Dockerfile can
# assemble all five from the side-by-side checkout — exactly what CI does for
# the `build` branch, but locally and without a token:
#
#   docker build \
#     --build-context auth=../tds-auth-api \
#     --build-context contact=../tds-contact-api \
#     --build-context content=../tds-content-api \
#     --build-context customer=../tds-customer-api \
#     -t tds-api .
#
# `docker compose up` wires those contexts for you (see docker-compose.yml).

############################################
# Stage 1 — install production PHP vendors #
############################################
FROM composer:2 AS vendor

WORKDIR /build
COPY . ./gateway/
COPY --from=auth     . ./auth/
COPY --from=contact  . ./contact/
COPY --from=content  . ./content/
COPY --from=customer . ./customer/

# Prod deps for every piece. Then re-add phinx for the four services (they
# keep it in require-dev, but the running container needs the migration
# runner) — same trick the assemble workflow uses for the build bundle.
RUN set -eux; \
    for d in gateway auth contact content customer; do \
      rm -rf "/build/$d/vendor" "/build/$d/.git"; \
      composer install --working-dir="/build/$d" \
        --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader; \
    done; \
    for d in auth contact content customer; do \
      c=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["require-dev"]["robmorgan/phinx"] ?? "";' "/build/$d/composer.json"); \
      if [ -n "$c" ]; then \
        composer require "robmorgan/phinx:$c" --working-dir="/build/$d" \
          --no-interaction --no-progress --update-no-dev --optimize-autoloader; \
      fi; \
    done

##########################
# Stage 2 — the runtime  #
##########################
FROM php:8.3-cli-bookworm AS runtime

# pdo_mysql + mbstring are the only extensions not bundled in the official
# image; curl, openssl, json, fileinfo already are. supervisor runs the five
# processes; the mysql client lets the entrypoint wait for the DB.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      libonig-dev supervisor default-mysql-client; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /srv/tds

COPY --from=vendor /build/gateway/  ./gateway/
COPY --from=vendor /build/auth/     ./services/auth/
COPY --from=vendor /build/contact/  ./services/contact/
COPY --from=vendor /build/content/  ./services/content/
COPY --from=vendor /build/customer/ ./services/customer/

COPY deploy/supervisord.docker.conf /etc/supervisor/conf.d/tds.conf
COPY deploy/docker-entrypoint.sh    /usr/local/bin/tds-entrypoint
RUN chmod +x /usr/local/bin/tds-entrypoint

# Only the gateway is published; the four services stay on the loopback.
EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/tds-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
