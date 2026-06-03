#!/usr/bin/env python3
"""
Experiment-4 analyzer — SPIRE vs Linkerd 1.x downstream fault recovery.

Reads recovery_spire.csv and recovery_linkerd.csv (schema produced by
fault-recovery-common.sh), compares by (scenario, fault_duration_sec):

    recovery_sec   — mean / median / p95   (SPIRE must be lower → wins)
    saga_completed — completion rate
    rollback_count — total compensation markers in the fault window

Charts (NO box):
    恢復時間_bar.png       grouped Bar per scenario, SPIRE vs Linkerd median recovery
    恢復時間_趨勢.png       Line over the fault_duration sweep (subplot per scenario)
    saga完成率_bar.png     grouped Bar, completion rate per scenario
    rollback次數_bar.png   grouped Bar, total rollbacks per scenario
    fault_recovery_summary.xlsx   aggregated table + verdict sheet (SPIRE wins?)

Usage:
    analyze-fault-recovery.py --spire recovery_spire.csv \
        --linkerd recovery_linkerd.csv --out artifacts/fault-recovery-<date>
"""
from __future__ import annotations

import argparse
import math
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.font_manager as fm
import matplotlib.pyplot as plt
import pandas as pd

STACK_COLORS = {"spire": "#1f77b4", "linkerd": "#7f7f7f"}
STACK_LABEL = {"spire": "SPIRE (SPIFFE)", "linkerd": "Linkerd 1.x"}


def setup_cjk() -> None:
    cands = ["PingFang TC", "Heiti TC", "Arial Unicode MS",
             "Noto Sans CJK TC", "Noto Sans CJK SC", "Noto Sans CJK JP",
             "Microsoft JhengHei", "SimHei"]
    avail = {f.name for f in fm.fontManager.ttflist}
    pick = next((c for c in cands if c in avail), None)
    if pick:
        plt.rcParams["font.sans-serif"] = [pick, "DejaVu Sans"]
    plt.rcParams["axes.unicode_minus"] = False


def load(path: Path, stack: str) -> pd.DataFrame:
    if not path.exists():
        raise SystemExit(f"[exp4] missing CSV: {path}")
    df = pd.read_csv(path)
    df["stack"] = stack
    for col in ("recovery_sec", "saga_completed", "rollback_count",
                "fault_duration_sec"):
        df[col] = pd.to_numeric(df[col], errors="coerce")
    return df


def p95(s: pd.Series) -> float:
    s = pd.to_numeric(s, errors="coerce").dropna()
    return float(s.quantile(0.95)) if len(s) else float("nan")


def aggregate(df: pd.DataFrame) -> pd.DataFrame:
    rows = []
    keys = ["scenario", "fault_duration_sec", "stack"]
    for (scn, dur, stack), g in df.groupby(keys, dropna=False):
        rec = g["recovery_sec"].dropna()
        rows.append({
            "scenario": scn,
            "fault_duration_sec": dur,
            "stack": stack,
            "n": int(len(g)),
            "recovery_mean": float(rec.mean()) if len(rec) else float("nan"),
            "recovery_median": float(rec.median()) if len(rec) else float("nan"),
            "recovery_p95": p95(rec),
            "saga_completed_rate": float(g["saga_completed"].mean())
                if len(g) else float("nan"),
            "rollback_total": int(g["rollback_count"].fillna(0).sum()),
        })
    return pd.DataFrame(rows)


def _grouped_bar(ax, scenarios, by_stack, value_key, fmt, ylabel, title):
    x = list(range(len(scenarios)))
    w = 0.38
    for i, stack in enumerate(("spire", "linkerd")):
        ys = [by_stack.get((scn, stack), float("nan")) for scn in scenarios]
        bars = ax.bar([xi + (i - 0.5) * w for xi in x], ys, width=w,
                      color=STACK_COLORS[stack], alpha=0.85,
                      label=STACK_LABEL[stack])
        for b, v in zip(bars, ys):
            txt = "N/A" if (v != v) else fmt.format(v)
            ax.text(b.get_x() + b.get_width() / 2,
                    b.get_height() if v == v else 0, txt,
                    ha="center", va="bottom", fontsize=8)
    ax.set_xticks(x)
    ax.set_xticklabels(scenarios, rotation=15, ha="right")
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    ax.legend()
    ax.grid(axis="y", alpha=0.3)


def chart_recovery_bar(agg: pd.DataFrame, out_png: Path) -> None:
    # Median recovery per scenario (averaged across the duration sweep).
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["recovery_median"].mean())
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, by_stack, "recovery_median", "{:.2f}s",
                 "恢復時間 median (s)",
                 "下游故障恢復時間 — SPIRE vs Linkerd (越低越好)")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


def chart_recovery_trend(agg: pd.DataFrame, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    durs = sorted(agg["fault_duration_sec"].dropna().unique())
    n = len(scenarios)
    cols = min(2, n) or 1
    rows = math.ceil(n / cols)
    fig, axes = plt.subplots(rows, cols, figsize=(7 * cols, 4.5 * rows),
                             squeeze=False)
    for idx, scn in enumerate(scenarios):
        ax = axes[idx // cols][idx % cols]
        for stack in ("spire", "linkerd"):
            ys = []
            for d in durs:
                sub = agg[(agg["scenario"] == scn)
                          & (agg["fault_duration_sec"] == d)
                          & (agg["stack"] == stack)]
                ys.append(float(sub["recovery_median"].iloc[0])
                          if not sub.empty else float("nan"))
            ax.plot(durs, ys, marker="o", color=STACK_COLORS[stack],
                    label=STACK_LABEL[stack])
        ax.set_title(scn)
        ax.set_xlabel("fault_duration (s)")
        ax.set_ylabel("recovery median (s)")
        ax.grid(True, alpha=0.3)
        ax.legend(fontsize=8)
    # hide any unused subplots
    for j in range(n, rows * cols):
        axes[j // cols][j % cols].axis("off")
    fig.suptitle("恢復時間 趨勢 (隨 fault_duration sweep)")
    fig.tight_layout()
    fig.savefig(out_png, dpi=120); plt.close(fig)


def chart_rate_bar(agg: pd.DataFrame, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["saga_completed_rate"].mean()) * 100.0
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, by_stack, "rate", "{:.0f}%",
                 "saga 完成率 (%)",
                 "故障後 saga 完成率 — SPIRE vs Linkerd (公平性把關)")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


def chart_rollback_bar(agg: pd.DataFrame, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["rollback_total"].sum())
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, by_stack, "rollback", "{:.0f}",
                 "rollback 次數",
                 "故障窗內 rollback 標記數 — SPIRE vs Linkerd")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--spire", required=True)
    ap.add_argument("--linkerd", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    setup_cjk()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    df = pd.concat([load(Path(args.spire), "spire"),
                    load(Path(args.linkerd), "linkerd")], ignore_index=True)
    agg = aggregate(df)

    chart_recovery_bar(agg, out / "恢復時間_bar.png")
    chart_recovery_trend(agg, out / "恢復時間_趨勢.png")
    chart_rate_bar(agg, out / "saga完成率_bar.png")
    chart_rollback_bar(agg, out / "rollback次數_bar.png")

    # ── verdict: SPIRE median recovery < Linkerd median per scenario ──
    verdict_rows = []
    for scn in sorted(agg["scenario"].dropna().unique()):
        s = agg[(agg["scenario"] == scn) & (agg["stack"] == "spire")]["recovery_median"].mean()
        l = agg[(agg["scenario"] == scn) & (agg["stack"] == "linkerd")]["recovery_median"].mean()
        wins = bool(s < l) if (s == s and l == l) else None
        verdict_rows.append({
            "scenario": scn,
            "SPIRE recovery_median (s)": s,
            "Linkerd recovery_median (s)": l,
            "SPIRE_wins (lower)": wins,
            "note": "" if wins else "SPIRE not faster — investigate / re-run",
        })
    verdict_df = pd.DataFrame(verdict_rows)

    with pd.ExcelWriter(out / "fault_recovery_summary.xlsx", engine="openpyxl") as xw:
        agg.sort_values(["scenario", "fault_duration_sec", "stack"]).to_excel(
            xw, sheet_name="aggregated", index=False)
        verdict_df.to_excel(xw, sheet_name="verdict", index=False)

    all_win = all(r["SPIRE_wins (lower)"] for r in verdict_rows
                  if r["SPIRE_wins (lower)"] is not None)
    print(f"[exp4] outputs written to {out}")
    for f in sorted(out.iterdir()):
        print(f"  - {f.name}")
    print(f"[exp4] SPIRE beats Linkerd (lower recovery) in all scenarios: {all_win}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
