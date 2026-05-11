#!/usr/bin/env python3
"""
Aggregate per-stack security probe JSONL outputs into a single comparison
matrix. Reads any number of `<dir>/results.jsonl` inputs (one per stack run)
and produces:

  <out>/security_matrix.xlsx
      sheet "by_case"       — case_id × stack, cell = verdict
      sheet "by_case_status"— case_id × stack, cell = HTTP status / "—"
      sheet "by_category"   — category × stack, cell = "rejected/total"
      sheet "raw"           — concatenated JSONL
  <out>/block_rate.png      — grouped bar chart, x=category, hue=stack
  <out>/summary.md          — case-by-case narrative

Each input dir must contain a `results.jsonl` written by lib.sh's emit().
The stack name is read from the JSONL itself (every line has a "stack" key)
so input dir order does not matter.

Usage:
  python3 scripts/security/aggregate-security.py \
      --in artifacts/sec-baseline-20260508-101010 \
      --in artifacts/sec-linkerd-20260508-101300 \
      --out artifacts/sec-compare-20260508
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import pandas as pd

# Verdicts that mean the request reached/was processed by the target.
ACCEPTED = {"accepted", "accepted-by-broker", "reachable"}
# Verdicts that mean the target refused / network blocked.
REJECTED = {"rejected", "broker-rejected", "not-reachable", "blocked"}


def load_runs(in_dirs: list[Path]) -> pd.DataFrame:
    rows: list[dict] = []
    for d in in_dirs:
        jsonl = d / "results.jsonl"
        if not jsonl.exists():
            raise SystemExit(f"missing {jsonl}")
        with jsonl.open() as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                rows.append(json.loads(line))
    if not rows:
        raise SystemExit("no rows loaded")
    return pd.DataFrame(rows)


def normalise_verdict(v: str) -> str:
    if v in ACCEPTED:
        return "ACCEPT"
    if v in REJECTED:
        return "REJECT"
    if v.startswith("reachable("):
        return "ACCEPT"
    return v.upper()


def build_pivot(df: pd.DataFrame, value: str) -> pd.DataFrame:
    p = df.pivot_table(
        index="case_id",
        columns="stack",
        values=value,
        aggfunc="first",
    )
    p = p.sort_index()
    return p


def _attacks_only(df: pd.DataFrame) -> pd.DataFrame:
    if "is_attack" not in df.columns:
        return df
    return df[df["is_attack"] == 1]


def category_summary(df: pd.DataFrame) -> pd.DataFrame:
    df = _attacks_only(df).copy()
    df["normalised"] = df["verdict"].map(normalise_verdict)
    g = df.groupby(["category", "stack", "normalised"]).size().unstack("normalised", fill_value=0)
    g["total"] = g.sum(axis=1)
    g["reject_rate"] = (g.get("REJECT", 0) / g["total"]).round(3)
    g = g.reset_index().pivot(index="category", columns="stack",
                              values=["REJECT", "ACCEPT", "total", "reject_rate"])
    return g


def plot_block_rate(df: pd.DataFrame, out_png: Path) -> None:
    df = _attacks_only(df).copy()
    df["normalised"] = df["verdict"].map(normalise_verdict)
    by = (
        df.groupby(["category", "stack"])["normalised"]
        .apply(lambda s: (s == "REJECT").sum() / max(len(s), 1))
        .unstack("stack")
        .fillna(0.0)
    )
    if by.empty:
        return
    fig, ax = plt.subplots(figsize=(10, 5))
    by.plot(kind="bar", ax=ax)
    ax.set_ylabel("reject rate (attacks only)")
    ax.set_xlabel("category")
    ax.set_ylim(0, 1.05)
    ax.set_title("Reject rate by category (higher = more attacks blocked)")
    ax.legend(title="stack", loc="upper right")
    fig.tight_layout()
    fig.savefig(out_png, dpi=150)
    plt.close(fig)


def write_summary_md(df: pd.DataFrame, out_md: Path) -> None:
    attacks = _attacks_only(df).copy()
    attacks["normalised"] = attacks["verdict"].map(normalise_verdict)

    stacks = sorted(df["stack"].unique())
    lines: list[str] = []
    lines.append(f"# Security probe comparison — {len(stacks)} stacks\n")
    lines.append(f"Stacks: {', '.join(stacks)}")
    lines.append(f"Cases per stack: {len(df) // max(len(stacks), 1)} "
                 f"(attacks: {len(attacks) // max(len(stacks), 1)}, "
                 f"controls: {(len(df) - len(attacks)) // max(len(stacks), 1)})\n")

    lines.append("## Per-category reject rate (attacks only)\n")
    if not attacks.empty:
        by = (
            attacks.groupby(["category", "stack"])["normalised"]
            .apply(lambda s: (s == "REJECT").sum() / max(len(s), 1))
            .unstack("stack")
            .fillna(0.0)
            .round(3)
        )
        lines.append(by.to_markdown())
    else:
        lines.append("(no attack-flagged cases found)")
    lines.append("")

    # Control case sanity: every control should be ACCEPT on every stack.
    controls = df[df.get("is_attack", 1) == 0]
    if not controls.empty:
        controls = controls.copy()
        controls["normalised"] = controls["verdict"].map(normalise_verdict)
        bad = controls[controls["normalised"] != "ACCEPT"]
        if not bad.empty:
            lines.append("## ⚠ Control cases that did NOT accept\n")
            lines.append(bad[["stack", "case_id", "category", "status", "verdict", "notes"]].to_markdown(index=False))
            lines.append("")

    pivot = build_pivot(df, "verdict")
    if pivot.shape[1] > 1:
        diff = pivot[pivot.nunique(axis=1) > 1]
        lines.append(f"## Differential cases ({len(diff)} of {len(pivot)})\n")
        lines.append("Cases where at least one stack diverges from the others:\n")
        if not diff.empty:
            lines.append(diff.to_markdown())
        lines.append("")

    out_md.write_text("\n".join(lines))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="inputs", action="append", required=True,
                    type=Path, help="probe output directory (repeatable)")
    ap.add_argument("--out", required=True, type=Path,
                    help="aggregate output directory")
    args = ap.parse_args()

    args.out.mkdir(parents=True, exist_ok=True)
    df = load_runs(args.inputs)

    xlsx = args.out / "security_matrix.xlsx"
    with pd.ExcelWriter(xlsx, engine="openpyxl") as w:
        build_pivot(df, "verdict").to_excel(w, sheet_name="by_case")
        build_pivot(df, "status").to_excel(w, sheet_name="by_case_status")
        category_summary(df).to_excel(w, sheet_name="by_category")
        df.to_excel(w, sheet_name="raw", index=False)

    plot_block_rate(df, args.out / "block_rate.png")
    write_summary_md(df, args.out / "summary.md")
    print(f"wrote: {xlsx}")
    print(f"wrote: {args.out / 'block_rate.png'}")
    print(f"wrote: {args.out / 'summary.md'}")


if __name__ == "__main__":
    main()
