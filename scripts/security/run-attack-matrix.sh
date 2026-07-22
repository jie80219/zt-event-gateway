#!/usr/bin/env bash
# Drive the full 15-CVE attack matrix against one stack and emit a single CSV.
#
# Usage:
#   STACK=spiffe-keycloak bash scripts/security/run-attack-matrix.sh [OUT_DIR]
#   STACK=vault           bash scripts/security/run-attack-matrix.sh [OUT_DIR]
#
# Or to run both back-to-back into a comparison directory:
#   bash scripts/security/run-attack-matrix.sh --both [OUT_DIR]
#
# Output:
#   $OUT/raw/$STACK.csv   per-stack CSV (category, case_id, cve_ref, stack,
#                         result, latency_ms, target, detail)
#   $OUT/run.log          stdout/stderr from all probes
#
# 15-CVE backbone (full mapping in scripts/security/CVE-MATRIX.md):
#   #1  CVE-2024-1709   auth-bypass (http-ingress)      probe-tier1-auth-bypass
#   #2  CVE-2024-45409  lsvid-forgery                   probe-tier1-auth-bypass
#   #3  CVE-2024-3661   cross-host lateral              probe-tier1-lateral
#   #4  CVE-2024-38856  amqp-inject                     probe-tier1-lateral
#   #5  CVE-2024-21887/21893 replay                     probe-tier1-replay
#   #6  CVE-2024-3094   lib-hijack / supply chain       probe-tier1-lib-hijack
#   #7  CVE-2025-32463  privesc (id-bypass)             probe-tier2-privesc
#   #8  CVE-2024-21626  container isolation (config)    probe-tier2-config-audit
#   #9  CVE-2024-57727/57728 port-exposure              probe-tier2-config-audit
#   #10 CVE-2025-61757/CVE-2024-7401 identity-blast     probe-tier2-identity-blast
#   #11 CVE-2024-24919  credential-read       ★spiffe   probe-tier2-credential-exposure
#   #12 CVE-2024-3400   secret-zero           ★spiffe   probe-tier2-credential-exposure
#   #13 CVE-2024-23897  credential-lifetime   ★spiffe   probe-tier2-credential-exposure
#   #14 CVE-2024-37085  attestation           ★spiffe   probe-tier2-attestation
#   #15 CVE-2024-6387   observability-diff              probe-tier2-visibility

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PROBES=(
    probe-tier1-auth-bypass.sh
    probe-tier1-lateral.sh
    probe-tier1-replay.sh
    probe-tier1-lib-hijack.sh
    probe-tier2-privesc.sh
    probe-tier2-credential-exposure.sh
    probe-tier2-attestation.sh
    probe-tier2-config-audit.sh
    probe-tier2-visibility.sh
    probe-tier2-identity-blast.sh
)

run_one_stack() {
    local stack=$1 out=$2
    export STACK="$stack"
    export OUT="$out"
    mkdir -p "$OUT/raw"
    : >"$OUT/raw/${stack}.csv"
    echo "category,case_id,cve_ref,stack,result,latency_ms,target,detail" >"$OUT/raw/${stack}.csv"

    echo "==== running attack matrix on stack=$stack out=$OUT ===="
    local rc=0
    for p in "${PROBES[@]}"; do
        echo "---- $p ----" | tee -a "$OUT/run.log"
        if ! bash "$HERE/$p" 2>&1 | tee -a "$OUT/run.log"; then
            rc=$?
            echo "[WARN] probe $p exited rc=$rc — continuing" | tee -a "$OUT/run.log"
        fi
    done

    local rows
    rows=$(($(wc -l <"$OUT/raw/${stack}.csv") - 1))
    echo "==== stack=$stack done: $rows rows in $OUT/raw/${stack}.csv ===="
}

if [[ "${1:-}" == "--both" ]]; then
    shift
    out_root="${1:-artifacts/sec-compare-side-by-side-$(date +%Y%m%d-%H%M%S)}"
    mkdir -p "$out_root"
    for stk in spiffe-keycloak vault; do
        echo
        echo "##### switching to $stk #####"
        echo "(make sure the matching compose stack is healthy before continuing)"
        echo "Press Enter to proceed, or Ctrl-C to abort."
        read -r _
        run_one_stack "$stk" "$out_root"
    done
    echo
    echo "Both runs complete. Next:"
    echo "  python3 scripts/security/compare-stacks.py --in $out_root"
    exit 0
fi

if [[ -z "${STACK:-}" ]]; then
    echo "ERROR: set STACK=spiffe-keycloak or STACK=vault, or pass --both" >&2
    exit 1
fi

out="${1:-artifacts/sec-${STACK}-$(date +%Y%m%d-%H%M%S)}"
run_one_stack "$STACK" "$out"
echo
echo "Next:"
echo "  STACK=<the-other-stack> bash $0 ${out%/*}/sec-<other>-<ts>"
echo "  python3 scripts/security/compare-stacks.py --in <parent-dir>"
