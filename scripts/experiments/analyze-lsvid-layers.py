#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Analyze the unified LSVID per-layer benchmark (measure-lsvid-layers.php).

Reads warm.ndjson (+ optional cold.ndjson), and for each layer L0/L1/L2
reports the four metrics the thesis review asked to be kept distinct:

    incremental_extend   只計算新增一層的簽章時間
    e2e_extend           解析 + 驗證前一層 + 簽章 + 序列化
    cumulative_verify    validate() 整條鏈 L0..Ln（遞迴至 L0）
    incremental_verify   只驗新增的一層  = cumulative(Ln) − cumulative(L(n-1))
                         （聚合層級相減；L0 = cumulative(L0)）

Every metric is reported with BOTH mean and median (plus p95/p99/sd) so the
thesis can pick one statistic and use it consistently across text, tables
and figures.

Outputs into --out:
    summary.csv           long-form: mode,layer,metric,n,mean,median,p95,p99,sd,min,max
    thesis-table.md       the unified table (median primary, mean in parens)
    lsvid-layers.png      grouped-bar figure, extend panel + verify panel

Usage:
    python3 scripts/experiments/analyze-lsvid-layers.py \
        --warm scratch/warm.ndjson --cold scratch/cold.ndjson --out scratch/out
"""
import argparse
import json
import os
import statistics as st
from collections import defaultdict

LAYERS = ["L0", "L1", "L2"]
DIRECT_METRICS = ["incremental_extend_us", "e2e_extend_us", "cumulative_verify_us"]


def pct(sorted_vals, p):
    if not sorted_vals:
        return float("nan")
    k = (len(sorted_vals) - 1) * (p / 100.0)
    lo = int(k)
    hi = min(lo + 1, len(sorted_vals) - 1)
    frac = k - lo
    return sorted_vals[lo] * (1 - frac) + sorted_vals[hi] * frac


def load(path):
    """path -> {layer: {metric: [values]}}"""
    data = defaultdict(lambda: defaultdict(list))
    with open(path) as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            r = json.loads(line)
            layer = r["layer"]
            for m in DIRECT_METRICS:
                data[layer][m].append(float(r[m]))
    return data


def stats(vals):
    s = sorted(vals)
    return {
        "n": len(s),
        "mean": st.mean(s),
        "median": st.median(s),
        "p95": pct(s, 95),
        "p99": pct(s, 99),
        "sd": st.pstdev(s) if len(s) > 1 else 0.0,
        "min": s[0],
        "max": s[-1],
    }


def summarize(data):
    """-> {layer: {metric: statdict}} incl. derived incremental_verify_us."""
    out = {}
    for layer in LAYERS:
        out[layer] = {m: stats(data[layer][m]) for m in DIRECT_METRICS}
    # derive incremental_verify at aggregate level (mean & median),
    # from the per-layer cumulative_verify statistics.
    for i, layer in enumerate(LAYERS):
        cum = out[layer]["cumulative_verify_us"]
        if i == 0:
            inc_mean, inc_median = cum["mean"], cum["median"]
        else:
            prev = out[LAYERS[i - 1]]["cumulative_verify_us"]
            inc_mean = cum["mean"] - prev["mean"]
            inc_median = cum["median"] - prev["median"]
        out[layer]["incremental_verify_us"] = {"mean": inc_mean, "median": inc_median}
    return out


ALL_METRICS = [
    ("incremental_extend_us", "Incremental extend"),
    ("e2e_extend_us", "End-to-end extend"),
    ("incremental_verify_us", "Incremental verify"),
    ("cumulative_verify_us", "Cumulative verify"),
]


def write_csv(summaries, path):
    with open(path, "w") as fh:
        fh.write("mode,layer,metric,n,mean_us,median_us,p95_us,p99_us,sd_us,min_us,max_us\n")
        for mode, summ in summaries.items():
            for layer in LAYERS:
                for key, _ in ALL_METRICS:
                    s = summ[layer][key]
                    fh.write(
                        f"{mode},{layer},{key},"
                        f"{s.get('n','')},{s['mean']:.3f},{s['median']:.3f},"
                        f"{s.get('p95',float('nan')):.3f},{s.get('p99',float('nan')):.3f},"
                        f"{s.get('sd',float('nan')):.3f},"
                        f"{s.get('min',float('nan')):.3f},{s.get('max',float('nan')):.3f}\n"
                    )


def write_thesis_table(summaries, path):
    lines = []
    lines.append("# LSVID 分層成本 — 統一表格（μs）\n")
    lines.append(
        "層級定義：**L0**＝Gateway 建立的 base token；**L1**＝第一個服務擴展後的 token；"
        "**L2**＝第二個服務擴展後的 token。\n"
    )
    lines.append(
        "指標定義：*Incremental extend* 只計新增一層的簽章；*End-to-end extend* 含解析、"
        "驗證前一層、簽章與序列化；*Incremental verify* 只驗新增一層"
        "（＝ cumulative(Ln) − cumulative(L(n-1))）；*Cumulative verify* 從最外層遞迴驗證至 L0。\n"
    )
    # ── Primary table: WARM steady state (production; SVID cache hot) ──
    summ = summaries["warm"]
    lines.append("\n## 主表 — Warm（生產穩態，SVID 快取命中）\n")
    lines.append("數值格式：中位數（平均）。\n")
    header = "| 指標 | " + " | ".join(LAYERS) + " |"
    sep = "|---|" + "---|" * len(LAYERS)
    lines.append(header)
    lines.append(sep)
    for key, label in ALL_METRICS:
        row = [label]
        for layer in LAYERS:
            s = summ[layer][key]
            med, mean = s["median"], s["mean"]
            if key == "e2e_extend_us" and layer == "L0":
                row.append(f"{med:.2f}（{mean:.2f}）†")
            else:
                row.append(f"{med:.2f}（{mean:.2f}）")
        lines.append("| " + " | ".join(row) + " |")
    lines.append("")
    lines.append("† L0 無入站 token，End-to-end extend 等同於 Incremental extend（純鑄造）。")

    # ── Cold-start penalty (first op after SVID rotation, caches empty) ──
    # Only L0 is well-defined for cold: within one request the crypto caches
    # warm up, so cross-layer differencing (incremental_verify) is invalid.
    if "cold" in summaries:
        cold = summaries["cold"]
        lines.append("\n## 附註 — Cold-start 首次請求 penalty（SVID 輪換後快取全冷，僅 L0 有意義）\n")
        lines.append(
            "說明：單一請求內建鏈時 openssl 憑證/私鑰快取會漸熱，故 L1/L2 的 cold 值不具"
            "獨立意義，此處僅列 L0（一次性 cache-fill 成本），用以解釋為何早期批次的 mint/"
            "verify 數字遠高於 warm 穩態。數值＝中位數（平均）。\n"
        )
        lines.append("| 指標（L0） | Warm | Cold |")
        lines.append("|---|---|---|")
        for key, label in [
            ("incremental_extend_us", "Incremental extend / mint"),
            ("cumulative_verify_us", "首次 verify（單層）"),
        ]:
            w = summ["L0"][key]
            c = cold["L0"][key]
            lines.append(
                f"| {label} | {w['median']:.2f}（{w['mean']:.2f}） "
                f"| {c['median']:.2f}（{c['mean']:.2f}） |"
            )
        lines.append("")
    with open(path, "w") as fh:
        fh.write("\n".join(lines) + "\n")


def make_figure(summaries, path):
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt
        import numpy as np
    except ImportError:
        print("matplotlib not available — skipping figure")
        return

    summ = summaries.get("warm") or next(iter(summaries.values()))
    x = np.arange(len(LAYERS))
    w = 0.38

    fig, (ax1, ax2) = plt.subplots(1, 2, figsize=(11, 4.4))

    # Panel 1 — extend
    inc_ext = [summ[l]["incremental_extend_us"]["median"] for l in LAYERS]
    e2e_ext = [summ[l]["e2e_extend_us"]["median"] for l in LAYERS]
    b1 = ax1.bar(x - w / 2, inc_ext, w, label="Incremental extend", color="#4C72B0")
    b2 = ax1.bar(x + w / 2, e2e_ext, w, label="End-to-end extend", color="#DD8452")
    ax1.set_title("Extend cost (median, μs)")
    ax1.set_xticks(x); ax1.set_xticklabels(LAYERS)
    ax1.set_ylabel("μs"); ax1.legend(frameon=False)
    for bars in (b1, b2):
        for bar in bars:
            ax1.annotate(f"{bar.get_height():.1f}", (bar.get_x() + bar.get_width() / 2, bar.get_height()),
                         ha="center", va="bottom", fontsize=8)

    # Panel 2 — verify
    inc_ver = [summ[l]["incremental_verify_us"]["median"] for l in LAYERS]
    cum_ver = [summ[l]["cumulative_verify_us"]["median"] for l in LAYERS]
    b3 = ax2.bar(x - w / 2, inc_ver, w, label="Incremental verify", color="#55A868")
    b4 = ax2.bar(x + w / 2, cum_ver, w, label="Cumulative verify", color="#C44E52")
    ax2.set_title("Verify cost (median, μs)")
    ax2.set_xticks(x); ax2.set_xticklabels(LAYERS)
    ax2.set_ylabel("μs"); ax2.legend(frameon=False)
    for bars in (b3, b4):
        for bar in bars:
            ax2.annotate(f"{bar.get_height():.1f}", (bar.get_x() + bar.get_width() / 2, bar.get_height()),
                         ha="center", va="bottom", fontsize=8)

    fig.suptitle("LSVID per-layer cost — unified L0/L1/L2 (warm, median)")
    fig.tight_layout()
    fig.savefig(path, dpi=150)
    print(f"wrote {path}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--warm", required=True)
    ap.add_argument("--cold", default=None)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    summaries = {"warm": summarize(load(args.warm))}
    if args.cold and os.path.isfile(args.cold):
        summaries["cold"] = summarize(load(args.cold))

    write_csv(summaries, os.path.join(args.out, "summary.csv"))
    write_thesis_table(summaries, os.path.join(args.out, "thesis-table.md"))
    make_figure(summaries, os.path.join(args.out, "lsvid-layers.png"))

    # echo the thesis table to stdout for convenience
    with open(os.path.join(args.out, "thesis-table.md")) as fh:
        print(fh.read())


if __name__ == "__main__":
    main()
