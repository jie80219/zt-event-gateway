FROM ghcr.io/spiffe/spire-server:1.12.0 AS spire
FROM alpine:3.20
RUN apk add --no-cache openssl
COPY --from=spire /opt/spire/bin/spire-server /opt/spire/bin/spire-server
