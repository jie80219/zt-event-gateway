#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Regenerate the LSVID nesting comparison charts under the UNIFIED L0/L1/L2
convention (replaces the old L1–L4 charts from aggregate-nesting.py).

Reads warm NDJSON from measure-lsvid-layers.php (must carry the envelope
size fields) and emits three charts + a comparison table:

  chart_token_size.png        token size per layer, this work vs paper
  chart_latency_vs_paper.png  incremental extend + cumulative verify,
                              this work vs paper
  chart_keycloak_overhead.png LSVID token / envelope w/o KC / envelope w/ KC
  vs-paper-table.md           the numbers behind the charts

Layer alignment to the reference paper (which is 1-indexed from its base
token): our base token L0 == paper L1, so our L0/L1/L2 line up with the
paper's L1/L2/L3 by nesting depth (number of signatures in the chain).

Usage:
  python3 scripts/experiments/chart-lsvid-vs-paper.py \
      --warm docs/thesis/data/lsvid-layers/warm.ndjson \
      --out  docs/thesis/data/lsvid-layers
"""
import argparse
import json
import os
import statistics as st
from collections import defaultdict

LAYERS = ["L0", "L1", "L2"]

# Reference paper numbers (user-supplied), aligned by nesting depth:
# our L0 == paper L1, our L1 == paper L2, our L2 == paper L3.
PAPER = {
    "L0": {"extend_us": 76.96,  "validate_us": 108.07, "token_kb": 3.1, "paper_label": "L1"},
    "L1": {"extend_us": 114.14, "validate_us": 289.13, "token_kb": 5.7, "paper_label": "L2"},
    "L2": {"extend_us": 266.95, "validate_us": 464.72, "token_kb": 8.8, "paper_label": "L3"},
}

FIELDS = ["incremental_extend_us", "cumulative_verify_us",
          "bytes", "envelope_bytes_nokc", "envelope_bytes_kc"]


def load(path):
    data = defaultdict(lambda: defaultdict(list))
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            r = json.loads(line)
            for f in FIELDS:
                if f in r:
                    data[r["layer"]][f].append(float(r[f]))
    return data


def med(data, layer, field):
    return st.median(data[layer][field])


def make_charts(data, out):
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    import numpy as np

    x = np.arange(len(LAYERS))
    xlabels = [f"{l}\n(paper {PAPER[l]['paper_label']})" for l in LAYERS]

    def annotate(ax, bars, fmt="{:.2f}"):
        for b in bars:
            ax.annotate(fmt.format(b.get_height()),
                        (b.get_x() + b.get_width() / 2, b.get_height()),
                        ha="center", va="bottom", fontsize=8)

    # ── 1. Token size: this work vs paper ────────────────────────────────
    ours_kb = [med(data, l, "bytes") / 1024 for l in LAYERS]
    paper_kb = [PAPER[l]["token_kb"] for l in LAYERS]
    w = 0.38
    fig, ax = plt.subplots(figsize=(8.5, 5))
    b1 = ax.bar(x - w / 2, ours_kb, w, label="This work (LSVID)", color="#4C72B0")
    b2 = ax.bar(x + w / 2, paper_kb, w, label="Paper LSVID reference",
                color="#8172B3", hatch="//", edgecolor="black")
    annotate(ax, b1); annotate(ax, b2)
    for i, l in enumerate(LAYERS):
        pct = (paper_kb[i] - ours_kb[i]) / ours_kb[i] * 100
        ax.annotate(f"paper +{pct:.0f}%", (x[i] + w / 2, paper_kb[i]),
                    xytext=(0, 14), textcoords="offset points", ha="center",
                    fontsize=8, color="#8172B3",
                    bbox=dict(boxstyle="round,pad=0.2", fc="white", ec="#8172B3"))
    ax.set_title("LSVID nested token size — this work vs paper (unified L0–L2)")
    ax.set_xlabel("Layer"); ax.set_ylabel("Token size (kB)")
    ax.set_xticks(x); ax.set_xticklabels(xlabels)
    ax.set_ylim(top=max(paper_kb) * 1.22)
    ax.legend(frameon=False, loc="upper left"); ax.grid(axis="y", ls=":", alpha=0.5)
    fig.tight_layout(); fig.savefig(os.path.join(out, "chart_token_size.png"), dpi=150)
    plt.close(fig)

    # ── 2. Latency: extend + validate, this work vs paper ────────────────
    ours_ext = [med(data, l, "incremental_extend_us") for l in LAYERS]
    ours_val = [med(data, l, "cumulative_verify_us") for l in LAYERS]
    paper_ext = [PAPER[l]["extend_us"] for l in LAYERS]
    paper_val = [PAPER[l]["validate_us"] for l in LAYERS]
    w = 0.2
    fig, ax = plt.subplots(figsize=(10, 5))
    bA = ax.bar(x - 1.5 * w, ours_ext, w, label="This work — Incremental extend", color="#55A868")
    bB = ax.bar(x - 0.5 * w, paper_ext, w, label="Paper — Extension", color="#55A868",
                hatch="//", edgecolor="black", alpha=0.6)
    bC = ax.bar(x + 0.5 * w, ours_val, w, label="This work — Cumulative verify", color="#C44E52")
    bD = ax.bar(x + 1.5 * w, paper_val, w, label="Paper — Validation", color="#C44E52",
                hatch="//", edgecolor="black", alpha=0.6)
    for bars in (bA, bB, bC, bD):
        annotate(ax, bars, "{:.1f}")
    ax.set_title("LSVID extend & verify time — this work vs paper (unified L0–L2, warm median)")
    ax.set_xlabel("Layer"); ax.set_ylabel("Time (μs)")
    ax.set_xticks(x); ax.set_xticklabels(xlabels)
    ax.set_ylim(top=max(paper_val) * 1.18)
    ax.legend(frameon=False, ncol=2, fontsize=8, loc="upper left"); ax.grid(axis="y", ls=":", alpha=0.5)
    fig.tight_layout(); fig.savefig(os.path.join(out, "chart_latency_vs_paper.png"), dpi=150)
    plt.close(fig)

    # ── 3. Keycloak envelope overhead ────────────────────────────────────
    tok_kb = [med(data, l, "bytes") / 1024 for l in LAYERS]
    env_nokc = [med(data, l, "envelope_bytes_nokc") / 1024 for l in LAYERS]
    env_kc = [med(data, l, "envelope_bytes_kc") / 1024 for l in LAYERS]
    w = 0.27
    fig, ax = plt.subplots(figsize=(8.5, 5))
    g1 = ax.bar(x - w, tok_kb, w, label="LSVID token only", color="#4C72B0")
    g2 = ax.bar(x, env_nokc, w, label="Envelope w/o Keycloak", color="#55A868")
    g3 = ax.bar(x + w, env_kc, w, label="Envelope w/ Keycloak", color="#DD8452")
    for bars in (g1, g2, g3):
        annotate(ax, bars)
    for i in range(len(LAYERS)):
        delta = env_kc[i] - env_nokc[i]
        ax.annotate(f"+{delta:.2f} kB", (x[i] + w, env_kc[i]),
                    xytext=(0, 14), textcoords="offset points", ha="center",
                    fontsize=8, color="#C44E52",
                    bbox=dict(boxstyle="round,pad=0.2", fc="white", ec="#C44E52"))
    ax.set_title("Envelope size breakdown: LSVID + Keycloak overhead (unified L0–L2)")
    ax.set_xlabel("Layer"); ax.set_ylabel("Size (kB)")
    ax.set_xticks(x); ax.set_xticklabels(xlabels)
    ax.set_ylim(top=max(env_kc) * 1.2)
    ax.legend(frameon=False, loc="upper left"); ax.grid(axis="y", ls=":", alpha=0.5)
    fig.tight_layout(); fig.savefig(os.path.join(out, "chart_keycloak_overhead.png"), dpi=150)
    plt.close(fig)


def write_table(data, out):
    lines = ["# LSVID nesting — this work vs paper（統一 L0–L2）\n"]
    lines.append("層級深度對齊：我方 base token **L0 = paper L1**，故 L0/L1/L2 對 paper L1/L2/L3。")
    lines.append("我方數值為 warm 中位數；token/envelope 為中位數 kB。\n")
    lines.append("| Layer (paper) | Token this work (kB) | Token paper (kB) | Extend this work (μs) | Extend paper (μs) | Verify this work (μs) | Verify paper (μs) | Keycloak envelope overhead (kB) |")
    lines.append("|---|---|---|---|---|---|---|---|")
    for l in LAYERS:
        p = PAPER[l]
        tok = med(data, l, "bytes") / 1024
        ext = med(data, l, "incremental_extend_us")
        ver = med(data, l, "cumulative_verify_us")
        kc = (med(data, l, "envelope_bytes_kc") - med(data, l, "envelope_bytes_nokc")) / 1024
        lines.append(
            f"| {l} ({p['paper_label']}) | {tok:.2f} | {p['token_kb']:.2f} | "
            f"{ext:.2f} | {p['extend_us']:.2f} | {ver:.2f} | {p['validate_us']:.2f} | {kc:.2f} |"
        )
    lines.append("")
    lines.append("- **Token 體積**：我方每層均顯著小於 paper（nested LSVID 較精簡）。")
    lines.append("- **Extend / Verify**：我方 warm 穩態遠低於 paper 參考（不同實作/硬體）。")
    lines.append("- **Keycloak overhead**：固定 ≈1.52 kB 加法常數，與層數無關（access_token 平行置於 envelope，不在 LSVID 簽章內）。")
    with open(os.path.join(out, "vs-paper-table.md"), "w") as fh:
        fh.write("\n".join(lines) + "\n")
    print("\n".join(lines))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--warm", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)
    data = load(args.warm)
    if "envelope_bytes_nokc" not in data["L0"]:
        raise SystemExit("warm NDJSON lacks envelope fields — re-run measure-lsvid-layers.php")
    make_charts(data, args.out)
    write_table(data, args.out)
    print(f"\nwrote charts + table to {args.out}")


if __name__ == "__main__":
    main()
