#!/usr/bin/env python3
"""
Experiment-4 analyzer — downstream fault-recovery comparison across N stacks.

Reads one recovery_<stack>.csv per stack (schema produced by
fault-recovery-common.sh) and compares by (scenario, fault_duration_sec):

    recovery_sec   — mean / median / p95   (lower = better → ranked)
    saga_completed — completion rate
    rollback_count — total compensation markers in the fault window

Any number of stacks is supported (spire, linkerd, vault, vault-pki, …). Pass
them via the convenience flags or the generic --csv stack=path form:

    # convenience shorthands (any subset, 1+):
    analyze-fault-recovery.py \
        --spire recovery_spire.csv \
        --linkerd recovery_linkerd.csv \
        --vault-pki artifacts/.../recovery_vault-pki.csv \
        --out artifacts/fault-recovery-<date>

    # generic form — works for ANY stack label (repeatable):
    analyze-fault-recovery.py \
        --csv vault-pki=artifacts/.../recovery_vault-pki.csv \
        --csv spire=recovery_spire.csv \
        --out artifacts/fault-recovery-<date>

Optional --baseline <stack> answers "does this stack have the lowest recovery
in every scenario?" (the old SPIRE-vs-Linkerd verdict, generalized). If omitted,
the verdict sheet just ranks stacks per scenario by median recovery.

Charts (NO box):
    恢復時間_bar.png       grouped Bar per scenario, median recovery per stack
    恢復時間_趨勢.png       Line over the fault_duration sweep (subplot per scenario)
    saga完成率_bar.png     grouped Bar, completion rate per scenario
    rollback次數_bar.png   grouped Bar, total rollbacks per scenario
    fault_recovery_summary.xlsx   aggregated table + ranking/verdict sheet
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

# Known stacks get stable colors/labels; anything else falls back to a cycle.
KNOWN_COLORS = {
    "spire": "#1f77b4",
    "linkerd": "#7f7f7f",
    "vault": "#2ca02c",
    "vault-pki": "#d62728",
    "main": "#9467bd",
}
KNOWN_LABEL = {
    "spire": "SPIRE (SPIFFE)",
    "linkerd": "Linkerd 1.x",
    "vault": "Vault PKI",
    "vault-pki": "Vault PKI (split)",
    "main": "baseline (main)",
}
_FALLBACK_CYCLE = ["#ff7f0e", "#8c564b", "#e377c2", "#bcbd22", "#17becf",
                   "#aec7e8", "#ffbb78", "#98df8a"]


def stack_color(stack: str, order: list[str]) -> str:
    if stack in KNOWN_COLORS:
        return KNOWN_COLORS[stack]
    # deterministic fallback based on position among the unknown stacks
    unknown = [s for s in order if s not in KNOWN_COLORS]
    return _FALLBACK_CYCLE[unknown.index(stack) % len(_FALLBACK_CYCLE)]


def stack_label(stack: str) -> str:
    return KNOWN_LABEL.get(stack, stack)


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


def _grouped_bar(ax, scenarios, stacks, by_stack, fmt, ylabel, title):
    x = list(range(len(scenarios)))
    n = max(len(stacks), 1)
    w = min(0.8 / n, 0.38)
    for i, stack in enumerate(stacks):
        ys = [by_stack.get((scn, stack), float("nan")) for scn in scenarios]
        offset = (i - (n - 1) / 2) * w
        bars = ax.bar([xi + offset for xi in x], ys, width=w,
                      color=stack_color(stack, stacks), alpha=0.85,
                      label=stack_label(stack))
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


def chart_recovery_bar(agg, stacks, out_png: Path) -> None:
    # Median recovery per scenario (averaged across the duration sweep).
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["recovery_median"].mean())
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, stacks, by_stack, "{:.2f}s",
                 "恢復時間 median (s)",
                 "下游故障恢復時間 — 各 stack 比較 (越低越好)")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


def chart_recovery_trend(agg, stacks, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    durs = sorted(agg["fault_duration_sec"].dropna().unique())
    n = len(scenarios)
    cols = min(2, n) or 1
    rows = math.ceil(n / cols)
    fig, axes = plt.subplots(rows, cols, figsize=(7 * cols, 4.5 * rows),
                             squeeze=False)
    for idx, scn in enumerate(scenarios):
        ax = axes[idx // cols][idx % cols]
        for stack in stacks:
            ys = []
            for d in durs:
                sub = agg[(agg["scenario"] == scn)
                          & (agg["fault_duration_sec"] == d)
                          & (agg["stack"] == stack)]
                ys.append(float(sub["recovery_median"].iloc[0])
                          if not sub.empty else float("nan"))
            ax.plot(durs, ys, marker="o", color=stack_color(stack, stacks),
                    label=stack_label(stack))
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


def chart_rate_bar(agg, stacks, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["saga_completed_rate"].mean()) * 100.0
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, stacks, by_stack, "{:.0f}%",
                 "saga 完成率 (%)",
                 "故障後 saga 完成率 — 各 stack 比較 (公平性把關)")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


def chart_rollback_bar(agg, stacks, out_png: Path) -> None:
    scenarios = sorted(agg["scenario"].dropna().unique())
    by_stack = {}
    for (scn, stack), g in agg.groupby(["scenario", "stack"]):
        by_stack[(scn, stack)] = float(g["rollback_total"].sum())
    fig, ax = plt.subplots(figsize=(11, 6.5))
    _grouped_bar(ax, scenarios, stacks, by_stack, "{:.0f}",
                 "rollback 次數",
                 "故障窗內 rollback 標記數 — 各 stack 比較")
    fig.tight_layout(); fig.savefig(out_png, dpi=120); plt.close(fig)


# Convenience flags map directly onto stack labels.
SHORTHAND_FLAGS = ["spire", "linkerd", "vault", "vault-pki", "main"]


def parse_sources(args) -> dict[str, Path]:
    """Build {stack: path} from both the --csv generic form and shorthands."""
    sources: dict[str, Path] = {}
    for item in (args.csv or []):
        if "=" not in item:
            raise SystemExit(f"[exp4] --csv expects stack=path, got: {item!r}")
        stack, _, path = item.partition("=")
        stack = stack.strip()
        if not stack or not path.strip():
            raise SystemExit(f"[exp4] --csv expects stack=path, got: {item!r}")
        sources[stack] = Path(path.strip())
    for flag in SHORTHAND_FLAGS:
        val = getattr(args, flag.replace("-", "_"))
        if val:
            sources[flag] = Path(val)
    return sources


def main() -> int:
    ap = argparse.ArgumentParser(
        description="Compare downstream fault-recovery across N stacks.")
    ap.add_argument("--csv", action="append", metavar="STACK=PATH",
                    help="generic, repeatable: e.g. --csv vault-pki=recovery_vault-pki.csv")
    for flag in SHORTHAND_FLAGS:
        ap.add_argument(f"--{flag}", metavar="PATH",
                        help=f"shorthand for --csv {flag}=PATH")
    ap.add_argument("--baseline", metavar="STACK",
                    help="stack expected to have the LOWEST recovery in every "
                         "scenario (adds a win/lose verdict column)")
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    sources = parse_sources(args)
    if not sources:
        raise SystemExit("[exp4] no input: pass --csv stack=path and/or a "
                         "shorthand flag (--spire/--linkerd/--vault/--vault-pki)")
    if args.baseline and args.baseline not in sources:
        raise SystemExit(f"[exp4] --baseline {args.baseline!r} not among input "
                         f"stacks: {sorted(sources)}")

    setup_cjk()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    # Preserve a stable stack order: known stacks first (in canonical order),
    # then any extra stacks in the order they were supplied.
    canonical = list(KNOWN_LABEL.keys())
    stacks = [s for s in canonical if s in sources] + \
             [s for s in sources if s not in canonical]

    df = pd.concat([load(sources[s], s) for s in stacks], ignore_index=True)
    agg = aggregate(df)

    chart_recovery_bar(agg, stacks, out / "恢復時間_bar.png")
    chart_recovery_trend(agg, stacks, out / "恢復時間_趨勢.png")
    chart_rate_bar(agg, stacks, out / "saga完成率_bar.png")
    chart_rollback_bar(agg, stacks, out / "rollback次數_bar.png")

    # ── ranking / verdict: rank stacks per scenario by median recovery ──
    verdict_rows = []
    all_baseline_wins = True if args.baseline else None
    for scn in sorted(agg["scenario"].dropna().unique()):
        # mean-of-median recovery per stack for this scenario
        per_stack = {}
        for stack in stacks:
            v = agg[(agg["scenario"] == scn) & (agg["stack"] == stack)][
                "recovery_median"].mean()
            per_stack[stack] = float(v)
        ranked = sorted((s for s in stacks if per_stack[s] == per_stack[s]),
                        key=lambda s: per_stack[s])
        best = ranked[0] if ranked else None
        row = {"scenario": scn}
        for stack in stacks:
            row[f"{stack} recovery_median (s)"] = per_stack[stack]
        row["best_stack (lowest)"] = best
        if args.baseline:
            b = per_stack.get(args.baseline, float("nan"))
            others = [per_stack[s] for s in stacks
                      if s != args.baseline and per_stack[s] == per_stack[s]]
            wins = bool(others) and b == b and all(b < o for o in others)
            row[f"{args.baseline}_wins (lowest)"] = wins
            if not wins:
                all_baseline_wins = False
                row["note"] = f"{args.baseline} not fastest — investigate / re-run"
            else:
                row["note"] = ""
        verdict_rows.append(row)
    verdict_df = pd.DataFrame(verdict_rows)

    sheet = "verdict" if args.baseline else "ranking"
    with pd.ExcelWriter(out / "fault_recovery_summary.xlsx",
                        engine="openpyxl") as xw:
        agg.sort_values(["scenario", "fault_duration_sec", "stack"]).to_excel(
            xw, sheet_name="aggregated", index=False)
        verdict_df.to_excel(xw, sheet_name=sheet, index=False)

    print(f"[exp4] stacks compared: {', '.join(stacks)}")
    print(f"[exp4] outputs written to {out}")
    for f in sorted(out.iterdir()):
        print(f"  - {f.name}")
    if args.baseline:
        print(f"[exp4] {args.baseline} has lowest recovery in all scenarios: "
              f"{all_baseline_wins}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
