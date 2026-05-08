#!/usr/bin/env python3
"""
Compare Linkerd_{5000,10000,20000}_Experimental vs the dual-mode
(SPIFFE+Keycloak) experiment summary, warm round only.

Outputs to <out>/:
  Gateway接收請求時間_對比.{xlsx,png}
  訂單完成時間_對比.{xlsx,png}
  未完成交易率_對比.{xlsx,png}
  mTLS花費時間_對比.{xlsx,png}
  comparison_summary.xlsx   (one combined table)
"""
from __future__ import annotations
import argparse
from pathlib import Path
import matplotlib
matplotlib.use("Agg")
import matplotlib.font_manager as fm
import matplotlib.pyplot as plt
import pandas as pd

SCALES = [5000, 10000, 20000]


def setup_cjk():
    cands = ["PingFang TC", "Heiti TC", "Arial Unicode MS",
             "Noto Sans CJK TC", "Noto Sans CJK SC", "Noto Sans CJK JP",
             "Microsoft JhengHei", "SimHei"]
    avail = {f.name for f in fm.fontManager.ttflist}
    pick = next((c for c in cands if c in avail), None)
    if pick:
        plt.rcParams["font.sans-serif"] = [pick, "DejaVu Sans"]
    plt.rcParams["axes.unicode_minus"] = False


def read_dualmode(summary_xlsx: Path):
    """Returns dict: scale -> {gw_mean, gw_p99, oc_mean, oc_p99, mtls_mean, rate_pct}."""
    out = {}
    gw = pd.read_excel(summary_xlsx, sheet_name="Gateway接收請求時間")
    oc = pd.read_excel(summary_xlsx, sheet_name="訂單完成時間")
    rate = pd.read_excel(summary_xlsx, sheet_name="未完成交易率")
    mtls = pd.read_excel(summary_xlsx, sheet_name="mTLS花費時間")
    for n in SCALES:
        gw_w = gw[(gw["scale"] == n) & (gw["round"] == "warm")].iloc[0]
        oc_w = oc[(oc["scale"] == n) & (oc["round"] == "warm")].iloc[0]
        rate_w = rate[(rate["scale"] == n) & (rate["round"] == "warm")].iloc[0]
        m_w = mtls[(mtls["scale"] == n) & (mtls["round"] == "warm")].iloc[0]
        out[n] = {
            "gw_mean": float(gw_w["mean"]),
            "gw_p99":  float(gw_w["p99"]),
            "oc_mean": float(oc_w["mean"]),
            "oc_p99":  float(oc_w["p99"]),
            "rate_pct": float(rate_w["incomplete_rate_pct"]),
            "mtls_mean": float(m_w["mean"]),
            "mtls_p99":  float(m_w["p99"]),
        }
    return out


def read_linkerd(base_dir: Path):
    """Read Linkerd_{N}_Experimental/summary.xlsx — single sheet, multi-row encoding."""
    out = {}
    for n in SCALES:
        p = base_dir / f"Linkerd_{n}_Experimental" / "summary.xlsx"
        df = pd.read_excel(p, sheet_name="summary")
        gw_row = df[df["metric"].str.contains("Gateway", na=False)].iloc[0]
        oc_row = df[df["metric"].str.contains("訂單完成", na=False)].iloc[0]
        rate_row = df[df["metric"].str.contains("未完成", na=False)].iloc[0]
        # Linkerd's "未完成交易率" sheet encodes mean=rate, count=total, min=success
        total = float(rate_row["count"])
        success = float(rate_row["min"])
        rate_pct = (total - success) / total * 100.0 if total else 0.0
        out[n] = {
            "gw_mean": float(gw_row["mean"]),
            "gw_p99":  float(gw_row["p99"]),
            "oc_mean": float(oc_row["mean"]),
            "oc_p99":  float(oc_row["p99"]),
            "rate_pct": rate_pct,
            "mtls_mean": float("nan"),  # sidecar mode — no PHP-side samples
            "mtls_p99":  float("nan"),
        }
    return out


def grouped_bar(ax, scales, vals_a, vals_b, label_a, label_b,
                color_a="#d62728", color_b="#2ca02c",
                annotate_fmt=None, log=False):
    x = list(range(len(scales)))
    w = 0.35
    bars_a = ax.bar([xi - w / 2 for xi in x], vals_a, width=w, color=color_a,
                    alpha=0.8, label=label_a)
    bars_b = ax.bar([xi + w / 2 for xi in x], vals_b, width=w, color=color_b,
                    alpha=0.8, label=label_b)
    if annotate_fmt:
        for bs, vs in [(bars_a, vals_a), (bars_b, vals_b)]:
            for bar, v in zip(bs, vs):
                if pd.isna(v):
                    txt = "N/A"
                else:
                    txt = annotate_fmt.format(v)
                ax.text(bar.get_x() + bar.get_width() / 2,
                        bar.get_height() if not pd.isna(v) else 0,
                        txt, ha="center", va="bottom", fontsize=9)
    ax.set_xticks(x)
    ax.set_xticklabels([str(s) for s in scales])
    ax.set_xlabel("請求數")
    if log:
        ax.set_yscale("log")
    ax.legend()
    ax.grid(axis="y", alpha=0.3)


def make_chart(out_png, title, ylabel, dm, lk, key, fmt, log=False):
    fig, ax = plt.subplots(figsize=(10, 6))
    vals_dm = [dm[s][key] for s in SCALES]
    vals_lk = [lk[s][key] for s in SCALES]
    grouped_bar(ax, SCALES, vals_dm, vals_lk,
                "SPIFFE+Keycloak (warm)", "Linkerd",
                annotate_fmt=fmt, log=log)
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dualmode", required=True,
                    help="Path to dual-mode experiment dir (containing summary.xlsx)")
    ap.add_argument("--artifacts-root", default="artifacts",
                    help="Root containing Linkerd_{N}_Experimental dirs")
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    setup_cjk()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    dm = read_dualmode(Path(args.dualmode) / "summary.xlsx")
    lk = read_linkerd(Path(args.artifacts_root))

    # ── Combined comparison table ──
    rows = []
    for n in SCALES:
        rows.append({
            "scale": n,
            "Gateway_mean (ms) — DualMode warm": dm[n]["gw_mean"],
            "Gateway_mean (ms) — Linkerd": lk[n]["gw_mean"],
            "Gateway_p99 (ms) — DualMode warm": dm[n]["gw_p99"],
            "Gateway_p99 (ms) — Linkerd": lk[n]["gw_p99"],
            "訂單完成_mean (ms) — DualMode warm": dm[n]["oc_mean"],
            "訂單完成_mean (ms) — Linkerd": lk[n]["oc_mean"],
            "訂單完成_p99 (ms) — DualMode warm": dm[n]["oc_p99"],
            "訂單完成_p99 (ms) — Linkerd": lk[n]["oc_p99"],
            "未完成率 % — DualMode warm": dm[n]["rate_pct"],
            "未完成率 % — Linkerd": lk[n]["rate_pct"],
            "mTLS_mean (ms) — DualMode probe": dm[n]["mtls_mean"],
            "mTLS_mean (ms) — Linkerd": lk[n]["mtls_mean"],
        })
    summary_df = pd.DataFrame(rows)
    summary_xlsx = out / "comparison_summary.xlsx"
    with pd.ExcelWriter(summary_xlsx, engine="openpyxl") as xw:
        summary_df.to_excel(xw, sheet_name="比較", index=False)

    # ── 1. Gateway 接收請求時間 ──
    make_chart(out / "Gateway接收請求時間_對比.png",
               "Gateway 接收請求時間 — SPIFFE+Keycloak (warm) vs Linkerd",
               "mean (ms)", dm, lk, "gw_mean", "{:.3f}", log=True)
    pd.DataFrame([
        {"scale": n,
         "DualMode warm mean (ms)": dm[n]["gw_mean"],
         "DualMode warm p99 (ms)": dm[n]["gw_p99"],
         "Linkerd mean (ms)": lk[n]["gw_mean"],
         "Linkerd p99 (ms)": lk[n]["gw_p99"],
         "倍數 (DualMode/Linkerd)": dm[n]["gw_mean"] / lk[n]["gw_mean"],
         } for n in SCALES
    ]).to_excel(out / "Gateway接收請求時間_對比.xlsx", index=False)

    # ── 2. 訂單完成時間 ──
    make_chart(out / "訂單完成時間_對比.png",
               "訂單完成時間 — SPIFFE+Keycloak (warm, 僅成功部分) vs Linkerd",
               "mean (ms, log scale)", dm, lk, "oc_mean", "{:.0f}", log=True)
    pd.DataFrame([
        {"scale": n,
         "DualMode warm mean (ms)": dm[n]["oc_mean"],
         "DualMode warm p99 (ms)": dm[n]["oc_p99"],
         "Linkerd mean (ms)": lk[n]["oc_mean"],
         "Linkerd p99 (ms)": lk[n]["oc_p99"],
         "倍數 (Linkerd/DualMode)": lk[n]["oc_mean"] / dm[n]["oc_mean"],
         } for n in SCALES
    ]).to_excel(out / "訂單完成時間_對比.xlsx", index=False)

    # ── 3. 未完成率 ──
    make_chart(out / "未完成交易率_對比.png",
               "未完成交易率 — SPIFFE+Keycloak (warm) vs Linkerd",
               "未完成率 (%)", dm, lk, "rate_pct", "{:.2f}%")
    pd.DataFrame([
        {"scale": n,
         "DualMode warm 未完成率 (%)": dm[n]["rate_pct"],
         "Linkerd 未完成率 (%)": lk[n]["rate_pct"],
         } for n in SCALES
    ]).to_excel(out / "未完成交易率_對比.xlsx", index=False)

    # ── 4. mTLS handshake — Linkerd N/A (sidecar) ──
    make_chart(out / "mTLS花費時間_對比.png",
               "mTLS handshake — SPIFFE+Keycloak probe vs Linkerd (sidecar 無樣本)",
               "mean (ms)", dm, lk, "mtls_mean", "{:.2f}")
    pd.DataFrame([
        {"scale": n,
         "DualMode probe mean (ms)": dm[n]["mtls_mean"],
         "DualMode probe p99 (ms)": dm[n]["mtls_p99"],
         "Linkerd": "N/A (sidecar mode — no PHP-side samples)",
         } for n in SCALES
    ]).to_excel(out / "mTLS花費時間_對比.xlsx", index=False)

    print(f"[compare] outputs written to {out}")
    for f in sorted(out.iterdir()):
        print(f"  - {f.name}")


if __name__ == "__main__":
    main()
