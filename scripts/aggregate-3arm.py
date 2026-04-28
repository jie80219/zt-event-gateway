#!/usr/bin/env python3
"""
Cross-arm aggregator for the 3-arm SPIFFE vs Keycloak experiments.

Reads per-arm artifacts produced by `scripts/run-3arm-experiment.sh`
(directories named `artifacts/3arm-{A,D,F}-*/`) and emits a unified
summary covering all three experiments:

    1. Latency  (zt-cost-matrix cells, p50/p95/p99 + delta vs baseline)
    2. Security (22-case attack matrix, rejected/accepted/defense_gap)
    3. Storage  (4 dimensions: static / wire / perhop / cache)

Output:
    {out}/3arm-final.json     — machine-readable, all data
    {out}/3arm-final.md       — human-readable tables for thesis

Usage:
    python3 scripts/aggregate-3arm.py --in artifacts/ --out artifacts/3arm-final-{stamp}/
    python3 scripts/aggregate-3arm.py --in artifacts/ --out out/  # picks latest run per arm

Conventions:
    Arm A = baseline (no identity)
    Arm D = SPIFFE+LSVID+mTLS
    Arm F = Keycloak Bearer (RS256)
"""

from __future__ import annotations

import argparse
import json
import sys
import re
from datetime import datetime, timezone
from pathlib import Path

ARMS = ("A", "D", "F")


def find_latest_arm_dirs(root: Path) -> dict[str, Path | None]:
    """Find the most recent `3arm-{ARM}-*` directory for each arm."""
    out: dict[str, Path | None] = {}
    for arm in ARMS:
        candidates = sorted(root.glob(f"3arm-{arm}-*"), reverse=True)
        out[arm] = candidates[0] if candidates else None
    return out


def load_meta(d: Path) -> dict:
    p = d / "meta.json"
    return json.loads(p.read_text()) if p.exists() else {}


def load_lat(d: Path) -> list[dict]:
    """Load all cell-*.json from arm/lat/."""
    cells = []
    for f in sorted((d / "lat").glob("cell-*.json")):
        try:
            cells.append({"_file": f.name, **json.loads(f.read_text())})
        except Exception as e:
            print(f"[lat] skip {f}: {e}", file=sys.stderr)
    return cells


def load_sec(d: Path) -> dict:
    """Look for security-summary.json or per-stage *.json in arm/sec/."""
    sec_dir = d / "sec"
    if not sec_dir.exists():
        return {}
    summary_path = sec_dir / "security-summary.json"
    if summary_path.exists():
        try:
            return json.loads(summary_path.read_text())
        except Exception as e:
            print(f"[sec] summary parse failed: {e}", file=sys.stderr)
    # Fallback: scan profile subdir
    cases = []
    for jf in sec_dir.rglob("*.json"):
        try:
            data = json.loads(jf.read_text())
        except Exception:
            continue
        for c in data.get("cases", []):
            c.setdefault("stage", data.get("stage", "unknown"))
            cases.append(c)
    return {"cases": cases}


def load_storage(d: Path) -> dict:
    storage_dir = d / "storage"
    out: dict[str, dict] = {}
    for dim in ("static", "wire", "perhop", "cache"):
        f = storage_dir / f"{dim}.json"
        if f.exists():
            try:
                out[dim] = json.loads(f.read_text())
            except Exception as e:
                out[dim] = {"_parse_error": str(e)}
    return out


# ── Latency aggregation ─────────────────────────────────────────────────────
def aggregate_latency(arm_data: dict[str, dict]) -> dict:
    """Build a cross-arm latency comparison table."""
    rows: list[dict] = []
    # Index by (payload, concurrency) → arm → metrics
    grid: dict[tuple[int, int], dict[str, dict]] = {}
    for arm, data in arm_data.items():
        for cell in data.get("lat", []):
            payload = cell.get("product_count")
            conc = cell.get("concurrency")
            if payload is None or conc is None:
                continue
            key = (payload, conc)
            grid.setdefault(key, {})[arm] = cell

    for (payload, conc), per_arm in sorted(grid.items()):
        ref = per_arm.get("A") or {}
        ref_p50 = (ref.get("latency_sec") or {}).get("p50", 0) * 1000
        ref_rps = ref.get("rate_per_sec") or 0
        row = {"payload": payload, "concurrency": conc}
        for arm in ARMS:
            c = per_arm.get(arm)
            if c is None:
                row[arm] = {"missing": True}
                continue
            lat = c.get("latency_sec") or {}
            p50_ms = round((lat.get("p50") or 0) * 1000, 3)
            p95_ms = round((lat.get("p95") or 0) * 1000, 3)
            p99_ms = round((lat.get("p99") or 0) * 1000, 3)
            rps = c.get("rate_per_sec") or 0
            row[arm] = {
                "p50_ms": p50_ms,
                "p95_ms": p95_ms,
                "p99_ms": p99_ms,
                "rps": rps,
                "delta_p50_pct_vs_A":
                    round((p50_ms - ref_p50) / ref_p50 * 100, 2) if ref_p50 else None,
                "delta_rps_pct_vs_A":
                    round((rps - ref_rps) / ref_rps * 100, 2) if ref_rps else None,
            }
        rows.append(row)
    return {"rows": rows, "arms_present": [a for a in ARMS if a in arm_data]}


# ── Security aggregation ────────────────────────────────────────────────────
def aggregate_security(arm_data: dict[str, dict]) -> dict:
    """Build a 22-case × 3-arm matrix with outcome per cell."""
    by_case: dict[str, dict[str, dict]] = {}
    for arm, data in arm_data.items():
        sec = data.get("sec", {})
        for c in sec.get("cases", []):
            cid = c.get("case_id") or c.get("id") or c.get("name")
            if not cid:
                continue
            outcome = c.get("outcome") or {}
            status = outcome.get("status")
            expected = outcome.get("is_expected")
            if status == "rejected" and expected:
                cell = "rejected_as_expected"
            elif status == "accepted" and not expected:
                cell = "accepted_violation"
            elif status == "accepted" and expected:
                cell = "accepted_expected"
            elif outcome.get("defense_gap") or c.get("defense_gap"):
                cell = "defense_gap"
            elif status == "error":
                cell = "error"
            else:
                cell = "unknown"
            by_case.setdefault(cid, {"category": c.get("category", "?")})[arm] = {
                "outcome": cell,
                "rejected_by": outcome.get("rejected_by"),
                "stage": c.get("stage"),
            }

    # Coverage tally
    coverage: dict[str, dict] = {}
    for arm in ARMS:
        if arm not in arm_data:
            continue
        tally = {"rejected_as_expected": 0, "accepted_violation": 0,
                 "accepted_expected": 0, "defense_gap": 0,
                 "error": 0, "unknown": 0, "total": 0}
        for cid, per in by_case.items():
            cell = (per.get(arm) or {}).get("outcome", "unknown")
            tally[cell] = tally.get(cell, 0) + 1
            tally["total"] += 1
        coverage[arm] = tally

    return {"matrix": by_case, "coverage": coverage}


# ── Storage aggregation ─────────────────────────────────────────────────────
def aggregate_storage(arm_data: dict[str, dict]) -> dict:
    """Side-by-side storage comparison across the four dimensions."""
    out: dict[str, dict] = {dim: {} for dim in ("static", "wire", "perhop", "cache")}
    for arm, data in arm_data.items():
        st = data.get("storage", {})
        out["static"][arm] = st.get("static", {})
        out["wire"][arm] = st.get("wire", {})
        out["perhop"][arm] = st.get("perhop", {})
        out["cache"][arm] = st.get("cache", {})
    return out


# ── Markdown rendering ──────────────────────────────────────────────────────
def render_markdown(summary: dict) -> str:
    lines = []
    lines.append("# 3-Arm SPIFFE vs Keycloak — Final Summary")
    lines.append("")
    lines.append(f"Generated: {summary['generated_at']}")
    lines.append("")
    lines.append("Arms:")
    for arm in ARMS:
        meta = summary["arms"].get(arm, {}).get("meta", {})
        if meta:
            lines.append(f"- **{arm}** = {meta.get('arm_label','?')} (branch: `{meta.get('branch_actual','?')}`, stamp: `{meta.get('stamp','?')}`)")
        else:
            lines.append(f"- **{arm}** = (no data)")
    lines.append("")

    # Latency
    lines.append("## Experiment 1 — Latency")
    lines.append("")
    lat = summary["latency"]
    lines.append("| payload | conc | A p50/p95/p99 ms | D p50/p95/p99 ms | F p50/p95/p99 ms | Δp50 D vs A | Δp50 F vs A |")
    lines.append("|---:|---:|:--|:--|:--|---:|---:|")
    for r in lat["rows"]:
        a, d, f = r.get("A", {}), r.get("D", {}), r.get("F", {})
        lines.append(
            f"| {r['payload']} | {r['concurrency']} | "
            f"{a.get('p50_ms','-')}/{a.get('p95_ms','-')}/{a.get('p99_ms','-')} | "
            f"{d.get('p50_ms','-')}/{d.get('p95_ms','-')}/{d.get('p99_ms','-')} | "
            f"{f.get('p50_ms','-')}/{f.get('p95_ms','-')}/{f.get('p99_ms','-')} | "
            f"{d.get('delta_p50_pct_vs_A','-')}% | "
            f"{f.get('delta_p50_pct_vs_A','-')}% |"
        )
    lines.append("")

    # Security
    lines.append("## Experiment 2 — Security Posture (22-case attack matrix)")
    lines.append("")
    sec = summary["security"]
    cov = sec.get("coverage", {})
    lines.append("**Coverage**:")
    lines.append("")
    lines.append("| arm | rejected_as_expected | accepted_violation | defense_gap | error | total |")
    lines.append("|:--|---:|---:|---:|---:|---:|")
    for arm in ARMS:
        c = cov.get(arm, {})
        if not c:
            continue
        lines.append(
            f"| {arm} | {c.get('rejected_as_expected',0)} | {c.get('accepted_violation',0)} | "
            f"{c.get('defense_gap',0)} | {c.get('error',0)} | {c.get('total',0)} |"
        )
    lines.append("")
    lines.append("**Per-case (showing first 30 cases)**:")
    lines.append("")
    lines.append("| case_id | category | A | D | F |")
    lines.append("|:--|:--|:--|:--|:--|")
    sym = {
        "rejected_as_expected": "✅ rej",
        "accepted_violation":   "❌ acc",
        "accepted_expected":    "✓ acc",
        "defense_gap":          "⚠ gap",
        "error":                "⚠ err",
        "unknown":              "?",
    }
    for cid, per in list(sec["matrix"].items())[:30]:
        cells = [(per.get(arm) or {}).get("outcome", "—") for arm in ARMS]
        lines.append(f"| {cid} | {per.get('category','?')} | {sym.get(cells[0],cells[0])} | {sym.get(cells[1],cells[1])} | {sym.get(cells[2],cells[2])} |")
    lines.append("")

    # Storage
    lines.append("## Experiment 3 — Storage Footprint")
    lines.append("")
    st = summary["storage"]

    lines.append("### Dim 1: Per-workload static credentials (bytes)")
    lines.append("")
    for arm in ARMS:
        s = st["static"].get(arm, {})
        if not s: continue
        lines.append(f"- **Arm {arm}**: " + json.dumps({k: v for k, v in s.items() if k != "entries"}, ensure_ascii=False))
        for e in s.get("entries", [])[:5]:
            lines.append(f"  - {e}")
    lines.append("")

    lines.append("### Dim 2: Per-request token wire size (bytes)")
    lines.append("")
    lines.append("| arm | envelope_token_bytes | header_token_bytes |")
    lines.append("|:--|---:|---:|")
    for arm in ARMS:
        w = st["wire"].get(arm, {})
        if not w: continue
        lines.append(f"| {arm} | {w.get('envelope_token_bytes','-')} | {w.get('header_token_bytes','-')} |")
    lines.append("")

    lines.append("### Dim 3: Per-hop token growth")
    lines.append("")
    for arm in ARMS:
        h = st["perhop"].get(arm, {})
        if not h: continue
        hops = h.get("hops", [])
        growth = h.get("growth_per_hop_bytes", [])
        lines.append(f"- **Arm {arm}**: {len(hops)} hops captured; bytes per hop: {[hop.get('bytes') for hop in hops]}; growth: {growth}")
    lines.append("")

    lines.append("### Dim 4: Cache / in-memory footprint")
    lines.append("")
    for arm in ARMS:
        c = st["cache"].get(arm, {})
        if not c: continue
        lines.append(f"- **Arm {arm}** (peak={c.get('memory_real_peak_bytes','-')} B):")
        for bucket in ("spiffe", "keycloak"):
            probes = (c.get("results", {}).get(bucket) or {}).get("probes", [])
            for p in probes:
                if p.get("available"):
                    lines.append(f"  - [{bucket}] {p.get('label')}: real Δ={p.get('mem_real_delta_bytes')} B, build={p.get('build_us')} µs")
    lines.append("")

    return "\n".join(lines)


# ── Main ────────────────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser(description="Aggregate 3-arm experiment artifacts")
    ap.add_argument("--in", dest="in_dir", required=True, help="artifacts/ root")
    ap.add_argument("--out", dest="out_dir", required=True, help="output dir for final summary")
    ap.add_argument("--arm-dir", action="append", default=[],
                    help="explicit arm dir override, format: A=path  (repeatable)")
    args = ap.parse_args()

    in_root = Path(args.in_dir)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    arm_dirs = find_latest_arm_dirs(in_root)
    for ov in args.arm_dir:
        if "=" not in ov:
            print(f"bad --arm-dir override: {ov}", file=sys.stderr)
            return 2
        a, p = ov.split("=", 1)
        if a not in ARMS:
            print(f"unknown arm in override: {a}", file=sys.stderr)
            return 2
        arm_dirs[a] = Path(p)

    arm_data: dict[str, dict] = {}
    for arm, d in arm_dirs.items():
        if d is None or not d.exists():
            print(f"[warn] no artifacts for arm {arm}", file=sys.stderr)
            continue
        arm_data[arm] = {
            "_dir":    str(d),
            "meta":    load_meta(d),
            "lat":     load_lat(d),
            "sec":     load_sec(d),
            "storage": load_storage(d),
        }

    summary = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "arms_found":   sorted(arm_data.keys()),
        "arms":         arm_data,
        "latency":      aggregate_latency(arm_data),
        "security":     aggregate_security(arm_data),
        "storage":      aggregate_storage(arm_data),
    }

    json_path = out_dir / "3arm-final.json"
    md_path   = out_dir / "3arm-final.md"
    json_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False, default=str))
    md_path.write_text(render_markdown(summary))
    print(f"wrote {json_path}")
    print(f"wrote {md_path}")
    print(f"arms in output: {summary['arms_found']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
