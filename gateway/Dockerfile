# syntax=docker/dockerfile:1.7
#
# One image that runs the whole TDS API surface: the Slim gateway on :8000
# plus the backends (auth, customer, and the composed frontend API) on their
# loopback ports, supervised together.
#
# The gateway repo is the default build context. The service repos — and the
# frontend's extension packages — are pulled in as *named build contexts*
# (BuildKit) so a single Dockerfile can assemble them all from the side-by-side
# checkout, exactly what CI does for the `build` branch, but locally and
# without a token:
#
#   docker build \
#     --build-context auth=../tds-auth-api \
#     --build-context customer=../tds-customer-api \
#     --build-context frontend=../tds-core-frontend-api \
#     --build-context contract=../tds-frontend-contract-pkg \
#     --build-context ext_time_tracker=../tds-ext-time-tracker-pkg \
#     --build-context ext_customers=../tds-ext-customers-pkg \
#     --build-context ext_billing=../tds-ext-billing-pkg \
#     --build-context ext_lexware=../tds-ext-lexware-pkg \
#     --build-context ext_tools=../tds-ext-tools-pkg \
#     --build-context ext_messages=../tds-ext-messages-pkg \
#     --build-context ext_projects=../tds-ext-projects-pkg \
#     --build-context ext_documents=../tds-ext-documents-pkg \
#     --build-context ext_support_tickets=../tds-ext-support-tickets-pkg \
#     --build-context ext_contact_tickets=../tds-ext-contact-tickets-pkg \
#     --build-context ext_live_chat_cta=../tds-ext-live-chat-cta-pkg \
#     --build-context ext_website_cms=../tds-ext-website-cms-pkg \
#     --build-context ext_blog_cms=../tds-ext-blog-cms-pkg \
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
COPY --from=customer . ./customer/
COPY --from=frontend . ./frontend/
# frontend's Composer `path` repos resolve to these siblings of /build/frontend
# (../tds-frontend-contract-pkg + ../tds-ext-*). Keep the directory names EXACTLY
# as referenced in tds-core-frontend-api/composer.json.
COPY --from=contract          . ./tds-frontend-contract-pkg/
COPY --from=ext_time_tracker  . ./tds-ext-time-tracker-pkg/
COPY --from=ext_customers     . ./tds-ext-customers-pkg/
COPY --from=ext_billing       . ./tds-ext-billing-pkg/
COPY --from=ext_lexware       . ./tds-ext-lexware-pkg/
COPY --from=ext_tools         . ./tds-ext-tools-pkg/
COPY --from=ext_messages      . ./tds-ext-messages-pkg/
COPY --from=ext_projects      . ./tds-ext-projects-pkg/
COPY --from=ext_documents     . ./tds-ext-documents-pkg/
COPY --from=ext_support_tickets . ./tds-ext-support-tickets-pkg/
COPY --from=ext_contact_tickets . ./tds-ext-contact-tickets-pkg/
COPY --from=ext_live_chat_cta . ./tds-ext-live-chat-cta-pkg/
COPY --from=ext_website_cms   . ./tds-ext-website-cms-pkg/
COPY --from=ext_blog_cms      . ./tds-ext-blog-cms-pkg/

# Prod deps for the gateway + the two prefixed backends, then re-add phinx for
# those two (they keep it in require-dev, but the running container needs the
# migration runner). frontend composes its extensions via path repos: mirror
# (copy, don't symlink) them into vendor/ so the image is self-contained, and
# `composer update` so the tips of the sibling checkouts win over the lock. It
# already carries phinx in `require`.
#
# RUNTIME_PHP pins what Composer RESOLVES FOR. This image resolves in
# `composer:2`, whose bundled PHP is newer than the `php:8.3` runtime below —
# and the frontend is the one service installed with `composer update` (free
# resolution, so the sibling checkouts win over its lock). Without this it
# happily picked packages requiring PHP >= 8.4.1, Composer wrote that into
# `vendor/composer/platform_check.php`, and the runtime then FATALED on
# `require vendor/autoload.php` — before a single line of our code ran. In the
# gateway that surfaces as `"/frontend": {"status": 0}` in /healthz and a 502
# on every route, with nothing in the app logs, because the failure happens
# inside the autoloader.
#
# Keep this equal to the runtime stage's PHP. CI pins the same version
# (_assemble.yml `php-version`), which is what keeps the released bundle
# loadable on the production host — this line gives the image the same
# guarantee instead of inheriting whatever the builder happens to ship.
ARG RUNTIME_PHP=8.3
RUN set -eux; \
    for d in gateway auth customer frontend; do \
      composer config --working-dir="/build/$d" platform.php "$RUNTIME_PHP"; \
    done; \
    for d in gateway auth customer; do \
      rm -rf "/build/$d/vendor" "/build/$d/.git"; \
      composer install --working-dir="/build/$d" \
        --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader; \
    done; \
    for d in auth customer; do \
      c=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["require-dev"]["robmorgan/phinx"] ?? "";' "/build/$d/composer.json"); \
      if [ -n "$c" ]; then \
        composer require "robmorgan/phinx:$c" --working-dir="/build/$d" \
          --no-interaction --no-progress --update-no-dev --optimize-autoloader; \
      fi; \
    done; \
    rm -rf /build/frontend/vendor /build/frontend/.git; \
    COMPOSER_MIRROR_PATH_REPOS=1 composer update --working-dir="/build/frontend" \
      --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

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
COPY --from=vendor /build/customer/ ./services/customer/
COPY --from=vendor /build/frontend/ ./services/frontend/

COPY deploy/supervisord.docker.conf /etc/supervisor/conf.d/tds.conf
COPY deploy/docker-entrypoint.sh    /usr/local/bin/tds-entrypoint
RUN chmod +x /usr/local/bin/tds-entrypoint

# Only the gateway is published; the backends stay on the loopback.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/tds-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
