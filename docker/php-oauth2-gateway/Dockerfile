# Profile-E gateway image — OAuth 2.0 Bearer + Static JWT (HS256).
#
# Reuses the main php-openswoole base. Used only by docker-compose.profile-e.yml
# as the cross-architecture comparison baseline in the security suite
# (docs/experiment-comparison-targets.md §2.6).
#
# If you want a single shared image, you can drop this Dockerfile and just
# override the `command` in compose to run jwt-gateway.php. This file exists
# to keep the comparison target self-contained and independently buildable.

ARG BASE_IMAGE=zt-event-gateway-gateway
ARG BASE_TAG=latest
FROM ${BASE_IMAGE}:${BASE_TAG}

COPY docker/php-oauth2-gateway/jwt-gateway.php /app/docker/php-oauth2-gateway/jwt-gateway.php

CMD ["php", "/app/docker/php-oauth2-gateway/jwt-gateway.php"]
