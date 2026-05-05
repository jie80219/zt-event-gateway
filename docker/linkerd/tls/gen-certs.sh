#!/usr/bin/env bash
# Generate self-signed CA + per-host linkerd certs for mesh-internal mTLS.
# Run on the management host; then use distribute-certs.sh to rsync to 4 hosts.
#
# Usage: bash gen-certs.sh [HOST_A_IP HOST_B_IP HOST_C_IP HOST_D_IP]
#   defaults: 10.1.1.<a> 10.1.1.210 10.1.1.207 10.1.1.214
set -euo pipefail

HOST_A_IP="${1:-10.1.1.0}"
HOST_B_IP="${2:-10.1.1.210}"
HOST_C_IP="${3:-10.1.1.207}"
HOST_D_IP="${4:-10.1.1.214}"

cd "$(dirname "$0")"
mkdir -p ca certs

if [[ ! -f ca/ca.key ]]; then
  openssl genrsa -out ca/ca.key 4096
  openssl req -x509 -new -nodes -key ca/ca.key -days 3650 \
    -subj "/CN=zt-mesh-ca" -out ca/ca.crt
fi

gen_host_cert() {
  local label="$1" ip="$2"
  local key="certs/host-${label}.key" csr="certs/host-${label}.csr" crt="certs/host-${label}.crt"
  local cnf="certs/host-${label}.cnf"

  cat >"$cnf" <<EOF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no
[req_distinguished_name]
CN = linkerd-host-${label}
[v3_req]
subjectAltName = @alt_names
[alt_names]
DNS.1 = linkerd
DNS.2 = linkerd-host-${label}
IP.1 = ${ip}
EOF

  openssl genrsa -out "$key" 2048
  openssl req -new -key "$key" -out "$csr" -config "$cnf"
  openssl x509 -req -in "$csr" -CA ca/ca.crt -CAkey ca/ca.key -CAcreateserial \
    -out "$crt" -days 365 -extensions v3_req -extfile "$cnf"
  rm -f "$csr" "$cnf"
}

gen_host_cert a "$HOST_A_IP"
gen_host_cert b "$HOST_B_IP"
gen_host_cert c "$HOST_C_IP"
gen_host_cert d "$HOST_D_IP"

# Stage public artifacts at the tls/ root so linkerd configs reference fixed paths.
cp ca/ca.crt ca.crt
for h in a b c d; do
  cp "certs/host-${h}.crt" "host-${h}.crt"
  cp "certs/host-${h}.key" "host-${h}.key"
done

echo "Generated:"
ls -1 ca.crt host-*.crt host-*.key
echo
echo "ca.key stays in ca/ — DO NOT distribute or commit."
