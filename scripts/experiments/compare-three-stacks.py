#!/usr/bin/env python3
"""
Three-stack comparison for Experiment-1:

    A  = SPIFFE+Keycloak+LSVID   (LSVID-on,  compose defaults)
    LK = Linkerd 1.x             (feat/Linkerd1)
    B  = SPIFFE+Keycloak         (LSVID-off, docker-compose.override.yml)

Target narrative (goal2.md §1): for 訂單完成時間 (order completion time),
A < LK < B at every scale — the full-LSVID mode is the fastest.

Reads each run's `summary.xlsx` (the format-reconciled surface) and emits,
per metric, a grouped **Bar** (3 stacks per scale) + a **Line** trend across
scales — NO box plots. The 訂單完成時間 outputs carry an `ordering_ok` column
and a combined `ordering_verdict` sheet that feeds the goal2.md §5 fine-tuning
decision.

Usage:
    compare-three-stacks.py \
        --lsvid-on  <dir-with-summary.xlsx> \
        --lsvid-off <dir-with-summary.xlsx> \
        --linkerd   <dir-with-summary.xlsx | artifacts-root> \
        --out artifacts/three-way-<date> \
        [--round warm] [--scales 5000,10000,20000] \
        [--linkerd-format multi|single]

`multi`  (default): --linkerd points at a dir whose summary.xlsx was produced
                    by analyze-dualmode-experiment.py (same multi-sheet schema
                    as the SPIFFE side — both branches share that analyzer).
`single`          : legacy layout — --linkerd is an artifacts-root containing
                    Linkerd_<scale>_Experimental/summary.xlsx single-sheet files.
"""
from __future__ import annotations

import argparse
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.font_manager as fm
import matplotlib.pyplot as plt
import pandas as pd

# Narrative order A → LK → B so the eye reads the hypothesis left-to-right.
STACK_LABELS = {
    "A":  "SPIFFE+Keycloak+LSVID",
    "LK": "Linkerd 1.x",
    "B":  "SPIFFE+Keycloak",
}
STACK_COLORS = {"A": "#1f77b4", "LK": "#7f7f7f", "B": "#d62728"}
STACK_ORDER = ("A", "LK", "B")


def setup_cjk() -> None:
    cands = ["PingFang TC", "Heiti TC", "Arial Unicode MS",
             "Noto Sans CJK TC", "Noto Sans CJK SC", "Noto Sans CJK JP",
             "Microsoft JhengHei", "SimHei"]
    avail = {f.name for f in fm.fontManager.ttflist}
    pick = next((c for c in cands if c in avail), None)
    if pick:
        plt.rcParams["font.sans-serif"] = [pick, "DejaVu Sans"]
    plt.rcParams["axes.unicode_minus"] = False


# ── Summary readers (reconcile SPIFFE multi-sheet vs Linkerd single-sheet) ──
def _f(row, *keys) -> float:
    """First present key → float, else NaN."""
    for k in keys:
        if k in row and pd.notna(row[k]):
            try:
                return float(row[k])
            except (TypeError, ValueError):
                pass
    return float("nan")


def read_multi(summary_xlsx: Path, scales: list[int], rnd: str) -> dict:
    """SPIFFE-style multi-sheet summary.xlsx (per-metric sheets, warm/cold rows)."""
    gw = pd.read_excel(summary_xlsx, sheet_name="Gateway接收請求時間")
    oc = pd.read_excel(summary_xlsx, sheet_name="訂單完成時間")
    rate = pd.read_excel(summary_xlsx, sheet_name="未完成交易率")
    try:
        mtls = pd.read_excel(summary_xlsx, sheet_name="mTLS花費時間")
    except Exception:
        mtls = None

    def pick(df, n):
        sub = df[(df["scale"] == n) & (df["round"] == rnd)]
        return sub.iloc[0] if not sub.empty else None

    out = {}
    for n in scales:
        gw_w, oc_w, rate_w = pick(gw, n), pick(oc, n), pick(rate, n)
        m_w = pick(mtls, n) if mtls is not None else None
        out[n] = {
            "gw_mean": _f(gw_w, "mean") if gw_w is not None else float("nan"),
            "gw_p50":  _f(gw_w, "p50") if gw_w is not None else float("nan"),
            "gw_p99":  _f(gw_w, "p99") if gw_w is not None else float("nan"),
            "oc_mean": _f(oc_w, "mean") if oc_w is not None else float("nan"),
            "oc_p50":  _f(oc_w, "p50") if oc_w is not None else float("nan"),
            "oc_p99":  _f(oc_w, "p99") if oc_w is not None else float("nan"),
            "rate_pct": _f(rate_w, "incomplete_rate_pct") if rate_w is not None else float("nan"),
            "mtls_mean": _f(m_w, "mean") if m_w is not None else float("nan"),
            "mtls_p50":  _f(m_w, "p50") if m_w is not None else float("nan"),
            "mtls_p99":  _f(m_w, "p99") if m_w is not None else float("nan"),
        }
    return out


def read_single(base_dir: Path, scales: list[int], rnd: str) -> dict:
    """Legacy Linkerd_<N>_Experimental/summary.xlsx — single 'summary' sheet,
    one row per metric. Rate encoded as count=total, min=success."""
    out = {}
    for n in scales:
        p = base_dir / f"Linkerd_{n}_Experimental" / "summary.xlsx"
        df = pd.read_excel(p, sheet_name="summary")

        def row_for(substr):
            sub = df[df["metric"].astype(str).str.contains(substr, na=False)]
            return sub.iloc[0] if not sub.empty else None

        gw_r, oc_r, rate_r = row_for("Gateway"), row_for("訂單完成"), row_for("未完成")
        if rate_r is not None:
            total = _f(rate_r, "count")
            success = _f(rate_r, "min")
            rate_pct = (total - success) / total * 100.0 if total else float("nan")
        else:
            rate_pct = float("nan")
        out[n] = {
            "gw_mean": _f(gw_r, "mean") if gw_r is not None else float("nan"),
            "gw_p50":  _f(gw_r, "p50") if gw_r is not None else float("nan"),
            "gw_p99":  _f(gw_r, "p99") if gw_r is not None else float("nan"),
            "oc_mean": _f(oc_r, "mean") if oc_r is not None else float("nan"),
            "oc_p50":  _f(oc_r, "p50") if oc_r is not None else float("nan"),
            "oc_p99":  _f(oc_r, "p99") if oc_r is not None else float("nan"),
            "rate_pct": rate_pct,
            "mtls_mean": float("nan"),  # sidecar mode — no PHP-side samples
            "mtls_p50":  float("nan"),
            "mtls_p99":  float("nan"),
        }
    return out


def read_summary(path: Path, fmt: str, scales: list[int], rnd: str) -> dict:
    if fmt == "single":
        return read_single(path, scales, rnd)
    return read_multi(path / "summary.xlsx", scales, rnd)


# ── Charts: grouped Bar (3 stacks/scale) + Line (3 stacks across scales) ────
def _series(data: dict, scales: list[int], key: str) -> list[float]:
    return [data[n][key] for n in scales]


def _autolog(*series_lists) -> bool:
    vals = [v for s in series_lists for v in s if v == v and v > 0]
    return bool(vals) and (max(vals) / max(min(vals), 1e-9) > 50)


def make_bar3(out_png: Path, title: str, ylabel: str, scales: list[int],
              series: dict[str, dict], key: str, fmt_txt: str) -> None:
    fig, ax = plt.subplots(figsize=(11, 6.5))
    x = list(range(len(scales)))
    w = 0.26
    log = _autolog(*[_series(series[s], scales, key) for s in STACK_ORDER])
    for i, s in enumerate(STACK_ORDER):
        ys = _series(series[s], scales, key)
        offset = (i - 1) * w
        bars = ax.bar([xi + offset for xi in x], ys, width=w,
                      color=STACK_COLORS[s], alpha=0.85, label=STACK_LABELS[s])
        for bar, v in zip(bars, ys):
            txt = "N/A" if v != v else fmt_txt.format(v)
            ax.text(bar.get_x() + bar.get_width() / 2,
                    bar.get_height() if v == v else 0, txt,
                    ha="center", va="bottom", fontsize=7, rotation=90)
    ax.set_xticks(x)
    ax.set_xticklabels([str(s) for s in scales])
    ax.set_xlabel("請求數")
    ax.set_ylabel(ylabel + (" (log)" if log else ""))
    ax.set_title(title)
    if log:
        ax.set_yscale("log")
    ax.legend(fontsize=8)
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def make_line3(out_png: Path, title: str, ylabel: str, scales: list[int],
               series: dict[str, dict], key: str) -> None:
    fig, ax = plt.subplots(figsize=(11, 6.5))
    x = list(range(len(scales)))
    log = _autolog(*[_series(series[s], scales, key) for s in STACK_ORDER])
    for s in STACK_ORDER:
        ys = _series(series[s], scales, key)
        ax.plot(x, ys, marker="o", color=STACK_COLORS[s], label=STACK_LABELS[s])
        for xi, v in zip(x, ys):
            if v == v:
                ax.annotate(f"{v:.1f}", (xi, v), textcoords="offset points",
                            xytext=(0, 5), ha="center", fontsize=7)
    ax.set_xticks(x)
    ax.set_xticklabels([str(s) for s in scales])
    ax.set_xlabel("請求數")
    ax.set_ylabel(ylabel + (" (log)" if log else ""))
    ax.set_title(title)
    if log:
        ax.set_yscale("log")
    ax.legend(fontsize=8)
    ax.grid(True, alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--lsvid-on", required=True, dest="lsvid_on")
    ap.add_argument("--lsvid-off", required=True, dest="lsvid_off")
    ap.add_argument("--linkerd", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--round", default="warm")
    ap.add_argument("--scales", default="5000,10000,20000")
    ap.add_argument("--linkerd-format", choices=["multi", "single"],
                    default="multi", dest="linkerd_format")
    args = ap.parse_args()

    setup_cjk()
    scales = [int(s) for s in args.scales.split(",")]
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    series = {
        "A":  read_summary(Path(args.lsvid_on), "multi", scales, args.round),
        "B":  read_summary(Path(args.lsvid_off), "multi", scales, args.round),
        "LK": read_summary(Path(args.linkerd), args.linkerd_format, scales, args.round),
    }

    # Per-metric: bar + line + xlsx. (key, title, ylabel, fmt, mean-key)
    metrics = [
        ("gw_mean", "gw", "Gateway接收請求時間",
         "Gateway 接收請求時間 (mean)", "mean (ms)", "{:.2f}"),
        ("oc_mean", "oc", "訂單完成時間",
         "訂單完成時間 (mean)", "完成時間 (ms)", "{:.0f}"),
        ("rate_pct", "rate", "未完成交易率",
         "未完成交易率", "未完成率 (%)", "{:.2f}"),
        ("mtls_mean", "mtls", "mTLS花費時間",
         "mTLS handshake (mean; Linkerd N/A)", "mean (ms)", "{:.2f}"),
    ]
    for key, short, fname, title, ylabel, fmt_txt in metrics:
        make_bar3(out / f"{fname}_3way.png", f"{title} — A/LK/B 對比 ({args.round})",
                  ylabel, scales, series, key, fmt_txt)
        make_line3(out / f"{fname}_3way_trend.png", f"{title} — 趨勢 ({args.round})",
                   ylabel, scales, series, key)
        # per-metric xlsx (mean/p50/p99 where applicable)
        rows = []
        for n in scales:
            row = {"scale": n}
            for s in STACK_ORDER:
                d = series[s][n]
                if short == "rate":
                    row[f"{STACK_LABELS[s]} 未完成率%"] = d["rate_pct"]
                else:
                    row[f"{STACK_LABELS[s]} mean"] = d[f"{short}_mean"]
                    row[f"{STACK_LABELS[s]} p50"] = d[f"{short}_p50"]
                    row[f"{STACK_LABELS[s]} p99"] = d[f"{short}_p99"]
            if short == "oc":
                a, lk, b = (series["A"][n]["oc_mean"], series["LK"][n]["oc_mean"],
                            series["B"][n]["oc_mean"])
                row["ordering_ok (A<LK<B)"] = bool(a < lk < b) if all(
                    v == v for v in (a, lk, b)) else None
            rows.append(row)
        pd.DataFrame(rows).to_excel(out / f"{fname}_3way.xlsx", index=False)

    # ── Combined summary + ordering verdict ──
    verdict_rows = []
    for n in scales:
        a, lk, b = (series["A"][n]["oc_mean"], series["LK"][n]["oc_mean"],
                    series["B"][n]["oc_mean"])
        ok = bool(a < lk < b) if all(v == v for v in (a, lk, b)) else None
        verdict_rows.append({
            "scale": n,
            "A oc_mean (LSVID-on)": a,
            "LK oc_mean (Linkerd)": lk,
            "B oc_mean (no-LSVID)": b,
            "ordering_ok (A<LK<B)": ok,
            "note": "" if ok else "FAIL — see goal2.md §5 fine-tuning loop",
        })
    verdict_df = pd.DataFrame(verdict_rows)

    combined_rows = []
    for n in scales:
        r = {"scale": n}
        for s in STACK_ORDER:
            d = series[s][n]
            tag = {"A": "LSVID-on", "LK": "Linkerd", "B": "no-LSVID"}[s]
            r[f"gw_mean[{tag}]"] = d["gw_mean"]
            r[f"oc_mean[{tag}]"] = d["oc_mean"]
            r[f"oc_p99[{tag}]"] = d["oc_p99"]
            r[f"rate%[{tag}]"] = d["rate_pct"]
        combined_rows.append(r)

    with pd.ExcelWriter(out / "comparison_three_summary.xlsx", engine="openpyxl") as xw:
        pd.DataFrame(combined_rows).to_excel(xw, sheet_name="combined", index=False)
        verdict_df.to_excel(xw, sheet_name="ordering_verdict", index=False)

    all_ok = all(r["ordering_ok (A<LK<B)"] for r in verdict_rows
                 if r["ordering_ok (A<LK<B)"] is not None)
    print(f"[compare3] outputs written to {out}")
    for f in sorted(out.iterdir()):
        print(f"  - {f.name}")
    print(f"[compare3] 訂單完成時間 ordering A<LK<B holds at all scales: {all_ok}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
