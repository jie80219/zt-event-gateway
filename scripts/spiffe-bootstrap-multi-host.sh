#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════
#  Multi-host SPIFFE cert bootstrap & distribution
#
#  Run on the gateway host (10.1.1.209) ONCE per cluster bring-up.
#
#  This script:
#    1. Ensures the agent-CA exists at spiffe/certs/agent-ca.{crt,key}.pem
#       (gateway repo already tracks these — the script only generates
#       a fresh CA if you removed/lost the existing one).
#    2. For each downstream service (Order/Production/User), signs a
#       fresh agent.{crt,key}.pem with the CA. The cert is dropped into
#       the local Services/<X>_service/spiffe/certs/ tree so the gateway
#       workload-registrar can compute its x509pop fingerprint.
#    3. SCPs each service's cert pair to the corresponding remote host
#       (10.1.1.210/.207/.214) under ~/<X>_service/spiffe/certs/.
#    4. Optionally SSHes into each remote host to docker-compose down+up
#       so the new cert takes effect (--restart flag).
#
#  The CA private key (agent-ca.key.pem) NEVER leaves the gateway host.
#
#  Prerequisites:
#    * passwordless SSH from gateway to each service host as ${SSH_USER}
#    * each service repo cloned at ~/<X>_service on its respective host
#    * docker compose installed on every host
#
#  Usage:
#    bash scripts/spiffe-bootstrap-multi-host.sh                # gen + distribute
#    bash scripts/spiffe-bootstrap-multi-host.sh --restart      # also recreate remote stacks
#    bash scripts/spiffe-bootstrap-multi-host.sh --force        # regen even if cert exists
#    SSH_USER=ubuntu bash scripts/spiffe-bootstrap-multi-host.sh
#
#  Override hosts:
#    ORDER_HOST=10.1.1.210 PRODUCTION_HOST=10.1.1.207 USER_HOST=10.1.1.214 \
#      bash scripts/spiffe-bootstrap-multi-host.sh
# ══════════════════════════════════════════════════════════════════
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_DIR"

# ── Config ──────────────────────────────────────────────────────────
SSH_USER="${SSH_USER:-root}"
ORDER_HOST="${ORDER_HOST:-10.1.1.210}"
PRODUCTION_HOST="${PRODUCTION_HOST:-10.1.1.207}"
USER_HOST="${USER_HOST:-10.1.1.214}"

CA_DIR="${PROJECT_DIR}/spiffe/certs"
CA_CRT="${CA_DIR}/agent-ca.crt.pem"
CA_KEY="${CA_DIR}/agent-ca.key.pem"
CA_SRL="${CA_DIR}/agent-ca.crt.srl"

# <local-dir-relative>:<remote-host>:<remote-dir-name>:<cert-CN>
SERVICES=(
  "Services/Order_service:${ORDER_HOST}:Order_service:order-agent"
  "Services/Production_service:${PRODUCTION_HOST}:Production_service:production-agent"
  "Services/User_service:${USER_HOST}:User_service:user-agent"
)

FORCE=0
RESTART=0
for arg in "$@"; do
  case "$arg" in
    --force)   FORCE=1 ;;
    --restart) RESTART=1 ;;
    -h|--help) sed -n '2,/^# ═*$/p' "$0"; exit 0 ;;
    *) echo "Unknown flag: $arg" >&2; exit 2 ;;
  esac
done

# ── Logging ─────────────────────────────────────────────────────────
BOLD='\033[1m'; GREEN='\033[32m'; YELLOW='\033[33m'; CYAN='\033[36m'; RED='\033[31m'; RESET='\033[0m'
log()  { echo -e "${BOLD}[bootstrap]${RESET} $(date +%T) $*"; }
ok()   { echo -e "  ${GREEN}✓${RESET} $*"; }
warn() { echo -e "  ${YELLOW}⚠${RESET} $*"; }
err()  { echo -e "  ${RED}✗${RESET} $*" >&2; }
step() { echo -e "\n${CYAN}${BOLD}── $* ──${RESET}"; }

# ── 1. CA ────────────────────────────────────────────────────────────
step "Step 1: ensure CA"
mkdir -p "$CA_DIR"
if [ -f "$CA_CRT" ] && [ -f "$CA_KEY" ] && [ "$FORCE" -ne 1 ]; then
  ok "agent-ca exists, reusing ($(openssl x509 -in "$CA_CRT" -noout -subject))"
else
  log "generating new CA (EC P-256, 10 years)..."
  openssl ecparam -name prime256v1 -genkey -noout -out "$CA_KEY"
  openssl req -new -x509 -key "$CA_KEY" \
    -subj "/C=TW/O=ZT/CN=SPIRE Agent CA" \
    -days 3650 -out "$CA_CRT"
  echo "01" > "$CA_SRL"
  chmod 600 "$CA_KEY"
  ok "wrote $CA_CRT + $CA_KEY"
fi

# ── 2. agent cert generator ─────────────────────────────────────────
gen_agent_cert() {
  local target_dir="$1" cn="$2"
  mkdir -p "$target_dir"
  if [ -f "${target_dir}/agent.crt.pem" ] && [ -f "${target_dir}/agent.key.pem" ] && [ "$FORCE" -ne 1 ]; then
    ok "  ${target_dir}/agent.{crt,key}.pem exists, skipping (--force to regen)"
    return
  fi
  openssl ecparam -name prime256v1 -genkey -noout \
    -out "${target_dir}/agent.key.pem" 2>/dev/null
  openssl req -new -key "${target_dir}/agent.key.pem" \
    -subj "/C=TW/O=ZT/CN=${cn}" \
    -out "${target_dir}/agent.csr" 2>/dev/null

  # SPIRE x509pop NodeAttestor signs the server's challenge with this key,
  # which requires keyUsage=digitalSignature on the leaf cert.
  local ext_file
  ext_file="$(mktemp)"
  cat > "$ext_file" <<'EOF'
keyUsage = critical, digitalSignature
basicConstraints = critical, CA:FALSE
subjectKeyIdentifier = hash
EOF

  openssl x509 -req -in "${target_dir}/agent.csr" \
    -CA "$CA_CRT" -CAkey "$CA_KEY" \
    -CAcreateserial -CAserial "$CA_SRL" \
    -days 3650 -sha256 \
    -extfile "$ext_file" \
    -out "${target_dir}/agent.crt.pem" 2>/dev/null

  rm -f "${target_dir}/agent.csr" "$ext_file"
  chmod 600 "${target_dir}/agent.key.pem"
  ok "  signed ${target_dir}/agent.{crt,key}.pem (CN=${cn})"
}

# ── 3. Gateway's own spire-agent cert ───────────────────────────────
step "Step 2: gateway spire-agent cert"
gen_agent_cert "$CA_DIR" "gateway-agent"

# ── 4. Per-service cert (local copy, also feeds workload-registrar) ─
step "Step 3: per-service certs (local + for workload-registrar)"
for entry in "${SERVICES[@]}"; do
  IFS=':' read -r local_dir host remote_dir cn <<<"$entry"
  gen_agent_cert "${PROJECT_DIR}/${local_dir}/spiffe/certs" "$cn"
done

# ── 5. Distribute to remote hosts in parallel ───────────────────────
step "Step 4: distribute to service hosts"
declare -a PIDS
for entry in "${SERVICES[@]}"; do
  IFS=':' read -r local_dir host remote_dir cn <<<"$entry"
  (
    log "→ ${cn} → ${SSH_USER}@${host}:~/${remote_dir}/spiffe/certs/"
    if ! ssh -o StrictHostKeyChecking=accept-new -o BatchMode=yes \
        "${SSH_USER}@${host}" "mkdir -p ~/${remote_dir}/spiffe/certs" 2>/dev/null; then
      err "ssh ${host} failed (passwordless SSH not configured?)"
      exit 11
    fi
    if scp -o BatchMode=yes \
        "${PROJECT_DIR}/${local_dir}/spiffe/certs/agent.crt.pem" \
        "${PROJECT_DIR}/${local_dir}/spiffe/certs/agent.key.pem" \
        "${SSH_USER}@${host}:~/${remote_dir}/spiffe/certs/" >/dev/null 2>&1; then
      ok "${host} cert delivered"
    else
      err "scp to ${host} failed"
      exit 12
    fi
  ) &
  PIDS+=($!)
done
ALL_OK=1
for pid in "${PIDS[@]}"; do
  wait "$pid" || ALL_OK=0
done
[ "$ALL_OK" -eq 1 ] || { err "one or more hosts failed in distribution; aborting"; exit 1; }

# ── 6. Optional: recreate remote service stacks ─────────────────────
if [ "$RESTART" -eq 1 ]; then
  step "Step 5: recreate remote service stacks (--restart)"
  PIDS=()
  for entry in "${SERVICES[@]}"; do
    IFS=':' read -r local_dir host remote_dir cn <<<"$entry"
    (
      log "→ docker compose down+up on ${host}"
      ssh -o BatchMode=yes "${SSH_USER}@${host}" "
        cd ~/${remote_dir} && \
        docker compose down --remove-orphans 2>&1 | tail -3 && \
        docker compose up -d 2>&1 | tail -5
      " || { err "${host} restart failed"; exit 21; }
      ok "${host} stack restarted"
    ) &
    PIDS+=($!)
  done
  for pid in "${PIDS[@]}"; do wait "$pid" || true; done
else
  step "Done"
  echo
  log "Cert generation + distribution complete."
  log "Run again with --restart to also recreate remote stacks, or do it manually:"
  for entry in "${SERVICES[@]}"; do
    IFS=':' read -r local_dir host remote_dir cn <<<"$entry"
    echo "    ssh ${SSH_USER}@${host} 'cd ~/${remote_dir} && docker compose down && docker compose up -d'"
  done
fi

step "Verification hints"
echo "  Gateway side:"
echo "    docker compose up -d --force-recreate workload-registrar"
echo "    docker logs zt-workload-registrar --tail 20"
echo "  Per service host (e.g. 10.1.1.210):"
echo "    docker logs <service>-spire-agent --tail 20"
echo "    docker exec <service>-spire-agent /opt/spire/bin/spire-agent api fetch x509 \\"
echo "      -socketPath /run/spire/sockets/agent.sock"
