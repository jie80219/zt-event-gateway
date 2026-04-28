#!/usr/bin/env python3
"""
3-Arm Plotter — render thesis-ready figures from `3arm-final.json`.

Two kinds of charts:

    --kind=lat      Latency comparison (p50/p95/p99 bar groups, RPS line)
    --kind=storage  Storage footprint (4 sub-plots in one figure)

Usage:
    python3 scripts/perf-suite/plot-3arm.py --in artifacts/3arm-final-{stamp}/3arm-final.json --kind=lat
    python3 scripts/perf-suite/plot-3arm.py --in artifacts/3arm-final-{stamp}/3arm-final.json --kind=storage
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

try:
    import matplotlib

    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
except ImportError:
    print("matplotlib not installed — pip install matplotlib", file=sys.stderr)
    sys.exit(1)

ARMS = ("A", "D", "F")
ARM_COLORS = {"A": "#888888", "D": "#0a7", "F": "#e60"}
ARM_LABELS = {"A": "A-baseline", "D": "D-SPIFFE+LSVID+mTLS", "F": "F-Keycloak-JWT"}


def plot_latency(summary: dict, out_path: Path) -> None:
    rows = summary.get("latency", {}).get("rows", [])
    if not rows:
        print("no latency rows to plot", file=sys.stderr)
        return

    fig, axes = plt.subplots(2, 2, figsize=(13, 9))
    fig.suptitle("Experiment 1 — Latency: SPIFFE vs Keycloak vs Baseline", fontsize=13)

    # Pivot: x-axis = "p{conc}/p{payload}" cells; y-axis = ms
    labels = [f"p{r['payload']}/c{r['concurrency']}" for r in rows]
    x = list(range(len(labels)))
    width = 0.27

    for ax, metric, title in (
        (axes[0][0], "p50_ms", "p50 latency (ms)"),
        (axes[0][1], "p95_ms", "p95 latency (ms)"),
        (axes[1][0], "p99_ms", "p99 latency (ms)"),
    ):
        for i, arm in enumerate(ARMS):
            ys = [(r.get(arm) or {}).get(metric) or 0 for r in rows]
            ax.bar([xi + (i - 1) * width for xi in x], ys,
                   width=width, color=ARM_COLORS[arm], label=ARM_LABELS[arm])
        ax.set_xticks(x)
        ax.set_xticklabels(labels, rotation=45, ha="right", fontsize=8)
        ax.set_ylabel(title)
        ax.set_title(title)
        ax.legend(fontsize=8)
        ax.grid(axis="y", linestyle=":", alpha=0.4)

    # RPS subplot
    ax = axes[1][1]
    for i, arm in enumerate(ARMS):
        ys = [(r.get(arm) or {}).get("rps") or 0 for r in rows]
        ax.plot(x, ys, marker="o", color=ARM_COLORS[arm], label=ARM_LABELS[arm])
    ax.set_xticks(x)
    ax.set_xticklabels(labels, rotation=45, ha="right", fontsize=8)
    ax.set_ylabel("Throughput (req/s)")
    ax.set_title("Throughput")
    ax.legend(fontsize=8)
    ax.grid(axis="y", linestyle=":", alpha=0.4)

    fig.tight_layout(rect=[0, 0, 1, 0.96])
    fig.savefig(out_path, dpi=130)
    print(f"wrote {out_path}")


def plot_storage(summary: dict, out_path: Path) -> None:
    st = summary.get("storage", {})
    fig, axes = plt.subplots(2, 2, figsize=(13, 9))
    fig.suptitle("Experiment 3 — Storage Footprint: SPIFFE vs Keycloak vs Baseline", fontsize=13)

    # Dim 1: static credentials — stacked bar per arm
    ax = axes[0][0]
    for arm_idx, arm in enumerate(ARMS):
        s = st.get("static", {}).get(arm, {})
        if not s:
            ax.bar(arm, 0, color=ARM_COLORS[arm], label=ARM_LABELS[arm])
            continue
        if arm == "D":
            cert = sum(e.get("cert_pem_bytes", 0) for e in s.get("entries", []))
            key  = sum(e.get("key_pem_bytes", 0) for e in s.get("entries", []))
            bund = sum(e.get("bundle_pem_bytes", 0) for e in s.get("entries", []))
            ax.bar(arm, cert, color="#0a7", label="cert_pem" if arm_idx == 0 else None)
            ax.bar(arm, key,  bottom=cert, color="#063", label="key_pem" if arm_idx == 0 else None)
            ax.bar(arm, bund, bottom=cert + key, color="#0c9", label="bundle_pem" if arm_idx == 0 else None)
        elif arm == "F":
            j = s.get("jwks_bytes", 0)
            o = s.get("oidc_discovery_bytes", 0)
            ax.bar(arm, j, color="#e60", label="JWKS" if arm_idx == 2 else None)
            ax.bar(arm, o, bottom=j, color="#a30", label="OIDC discovery" if arm_idx == 2 else None)
        else:
            ax.bar(arm, 0, color="#888", label="(none)" if arm_idx == 0 else None)
    ax.set_ylabel("bytes")
    ax.set_title("Dim 1: Static credentials")
    ax.legend(fontsize=8)
    ax.grid(axis="y", linestyle=":", alpha=0.4)

    # Dim 2: per-request wire size
    ax = axes[0][1]
    env_bytes = []
    hdr_bytes = []
    for arm in ARMS:
        w = st.get("wire", {}).get(arm, {}) or {}
        env_bytes.append(w.get("envelope_token_bytes", 0))
        hdr_bytes.append(w.get("header_token_bytes", 0))
    x = list(range(len(ARMS)))
    width = 0.35
    ax.bar([xi - width / 2 for xi in x], env_bytes, width, label="envelope token", color="#39c")
    ax.bar([xi + width / 2 for xi in x], hdr_bytes, width, label="HTTP header token", color="#f93")
    ax.set_xticks(x)
    ax.set_xticklabels([ARM_LABELS[a] for a in ARMS], rotation=15, fontsize=8)
    ax.set_ylabel("bytes")
    ax.set_title("Dim 2: Per-request wire size")
    ax.legend(fontsize=8)
    ax.grid(axis="y", linestyle=":", alpha=0.4)

    # Dim 3: per-hop growth
    ax = axes[1][0]
    for arm in ARMS:
        h = st.get("perhop", {}).get(arm, {}) or {}
        hops = h.get("hops") or []
        if not hops:
            continue
        ax.plot(
            [hop["hop"] for hop in hops],
            [hop["bytes"] for hop in hops],
            marker="o",
            color=ARM_COLORS[arm],
            label=ARM_LABELS[arm],
        )
    ax.set_xlabel("hop")
    ax.set_ylabel("token bytes")
    ax.set_title("Dim 3: Per-hop token growth")
    ax.legend(fontsize=8)
    ax.grid(linestyle=":", alpha=0.4)

    # Dim 4: cache footprint pie (per-arm subplots not feasible — show total real delta)
    ax = axes[1][1]
    cache_totals = {}
    for arm in ARMS:
        c = st.get("cache", {}).get(arm, {}) or {}
        bucket_results = c.get("results", {}) or {}
        total = 0
        for bucket in ("spiffe", "keycloak"):
            for p in (bucket_results.get(bucket) or {}).get("probes", []):
                if p.get("available"):
                    total += max(p.get("mem_real_delta_bytes") or 0, 0)
        cache_totals[arm] = total
    ax.bar(
        [ARM_LABELS[a] for a in ARMS],
        [cache_totals[a] for a in ARMS],
        color=[ARM_COLORS[a] for a in ARMS],
    )
    ax.set_ylabel("bytes")
    ax.set_title("Dim 4: Total cache footprint (real-mem delta sum)")
    for i, a in enumerate(ARMS):
        ax.text(i, cache_totals[a], f"{cache_totals[a]:,}", ha="center", va="bottom", fontsize=8)
    ax.grid(axis="y", linestyle=":", alpha=0.4)

    fig.tight_layout(rect=[0, 0, 1, 0.96])
    fig.savefig(out_path, dpi=130)
    print(f"wrote {out_path}")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="in_path", required=True, help="path to 3arm-final.json")
    ap.add_argument("--kind", choices=("lat", "storage"), required=True)
    ap.add_argument("--out", dest="out_path", default=None,
                    help="output PNG path (default: alongside input)")
    args = ap.parse_args()

    in_path = Path(args.in_path)
    summary = json.loads(in_path.read_text())
    out_path = Path(args.out_path) if args.out_path \
        else in_path.with_name(f"3arm-{args.kind}.png")

    if args.kind == "lat":
        plot_latency(summary, out_path)
    elif args.kind == "storage":
        plot_storage(summary, out_path)
    return 0


if __name__ == "__main__":
    sys.exit(main())
