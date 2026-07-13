#!/usr/bin/env bash
#
# capture-svid-serial.sh — 故障期間定時抓取 X.509-SVID，記錄序號與有效期
#
# 用途（Paper-5 / B 階段佐證）：
#   在故障注入期間每隔 N 秒抓一次 SPIRE agent 發出的 X.509-SVID，
#   把 (wall-clock 時間、SPIFFE ID、序號 serial、notBefore、notAfter) 寫進 CSV。
#   若整段 B 階段序號 (serial) 與 notAfter 都不變，即可證明用的是「同一張憑證」，
#   排除「其實有偷偷重簽 / 輪換」的質疑。序號一旦變動會即時標記 [ROTATED]。
#
# 前置：spire-agent 容器沒有 shell 也沒有 openssl，因此
#   - 用 `docker exec` 直接呼叫 agent binary，把 SVID 寫進 sockets volume
#   - 用「host 端」的 openssl 解析（host 可經 docker volume 直接讀到檔案）
#
# 用法：
#   bash scripts/security/capture-svid-serial.sh                 # 每 5s 抓一次，直到 Ctrl-C
#   INTERVAL=2 DURATION=600 OUT=/tmp/svid.csv bash scripts/security/capture-svid-serial.sh
#
# 環境變數：
#   CONTAINER  agent 容器名           (預設 zt-spire-agent)
#   SOCK       容器內 agent socket    (預設 /run/spire/sockets/agent.sock)
#   INTERVAL   抓取間隔秒數           (預設 5)
#   DURATION   總時長秒數，0=無限     (預設 0)
#   OUT        CSV 輸出路徑           (預設 artifacts/svid-serial-<UTC>.csv)
#
set -euo pipefail

CONTAINER="${CONTAINER:-zt-spire-agent}"
SOCK="${SOCK:-/run/spire/sockets/agent.sock}"
INTERVAL="${INTERVAL:-5}"
DURATION="${DURATION:-0}"
OUT="${OUT:-artifacts/svid-serial-$(date -u +%Y%m%dT%H%M%SZ).csv}"

# spire-agent 的 -write 目錄：直接用 socket 所在目錄（一定是可寫的 volume）
WRITE_DIR="$(dirname "$SOCK")"

# 解析出 host 端能直接讀到的 volume 路徑（避免硬寫死）
HOST_DIR="$(docker inspect "$CONTAINER" \
  --format "{{range .Mounts}}{{if eq .Destination \"$WRITE_DIR\"}}{{.Source}}{{end}}{{end}}")"
if [[ -z "$HOST_DIR" ]]; then
  echo "✗ 找不到 $CONTAINER 上 $WRITE_DIR 對應的 host 路徑（該目錄必須是 docker volume/bind mount）" >&2
  exit 1
fi

mkdir -p "$(dirname "$OUT")"
if [[ ! -f "$OUT" ]]; then
  echo "captured_at_utc,spiffe_id,serial,not_before,not_after,rotated" > "$OUT"
fi

echo "▶ container=$CONTAINER socket=$SOCK"
echo "▶ interval=${INTERVAL}s duration=${DURATION}s (0=∞)  out=$OUT"
echo "▶ 按 Ctrl-C 停止。序號變動會即時標記 [ROTATED]。"
echo

declare -A LAST_SERIAL   # spiffe_id -> 上一次序號

start=$(date +%s)
while :; do
  ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

  # 抓 SVID（寫進 sockets volume；host 端可直接讀）
  if ! docker exec "$CONTAINER" /opt/spire/bin/spire-agent api fetch x509 \
        -socketPath "$SOCK" -write "$WRITE_DIR/" >/dev/null 2>&1; then
    echo "$ts | ✗ fetch 失敗（agent 不可達？故障中？）"
    # 故障期間 fetch 失敗本身也是佐證：記一列 UNREACHABLE
    echo "$ts,FETCH_FAILED,,,," >> "$OUT"
  else
    # 逐一解析寫出的 svid.N.pem
    shopt -s nullglob
    for pem in "$HOST_DIR"/svid.*.pem; do
      spiffe_id="$(openssl x509 -in "$pem" -noout -ext subjectAltName 2>/dev/null \
                   | grep -oE 'spiffe://[^,[:space:]]+' | head -n1)"
      serial="$(openssl x509 -in "$pem" -noout -serial 2>/dev/null | cut -d= -f2)"
      nbf="$(openssl x509 -in "$pem" -noout -startdate 2>/dev/null | cut -d= -f2)"
      naf="$(openssl x509 -in "$pem" -noout -enddate  2>/dev/null | cut -d= -f2)"
      [[ -z "$spiffe_id" ]] && spiffe_id="(unknown)"

      rotated=""
      prev="${LAST_SERIAL[$spiffe_id]:-}"
      if [[ -n "$prev" && "$prev" != "$serial" ]]; then
        rotated="ROTATED"
      fi
      LAST_SERIAL[$spiffe_id]="$serial"

      flag=""; [[ -n "$rotated" ]] && flag="  [ROTATED ⚠  $prev → $serial]"
      printf '%s | %-32s serial=%s notAfter=%s%s\n' "$ts" "$spiffe_id" "$serial" "$naf" "$flag"
      echo "$ts,$spiffe_id,$serial,\"$nbf\",\"$naf\",$rotated" >> "$OUT"
    done
    shopt -u nullglob
  fi

  # 清掉私鑰與憑證，別把 SVID 私鑰留在 volume
  rm -f "$HOST_DIR"/svid.*.pem "$HOST_DIR"/svid.*.key "$HOST_DIR"/bundle.*.pem 2>/dev/null || true

  # 時長控制
  if [[ "$DURATION" != "0" ]]; then
    now=$(date +%s)
    (( now - start >= DURATION )) && { echo; echo "▶ 到達 DURATION=${DURATION}s，結束。"; break; }
  fi
  sleep "$INTERVAL"
done

echo
echo "=== 摘要（每個 SPIFFE ID 出現過的相異序號數）==="
tail -n +2 "$OUT" | awk -F, '$2!="FETCH_FAILED"{c[$2]++; s[$2","$3]=1}
  END{for(id in c){n=0; for(k in s) if(index(k,id",")==1)n++;
      printf "  %-32s samples=%d 相異序號=%d %s\n", id, c[id], n, (n==1?"→ 同一張憑證 ✅":"→ 有輪換 ⚠")}}'
echo "CSV: $OUT"
