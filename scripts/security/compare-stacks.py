#!/usr/bin/env python3
"""
Side-by-side comparison of two security-probe runs. Built on top of
aggregate-security.py's output but produces a richer comparison view:

  comparison.xlsx
      side_by_side       — every case_id with both stacks' status/verdict + diverges flag
      category_rates     — per-category REJECT / ACCEPT / total / reject_rate + delta
      divergences_only   — only cases where the two stacks disagree
      keycloak_only      — cases that exist on keycloak-spiffe but not on linkerd (F1-F7)
      raw                — concatenated JSONL

  comparison_heatmap.png — case_id × stack heatmap, coloured by verdict
  comparison_bars.png    — category × stack grouped bars (reject_rate)
  divergence_bars.png    — same bars but ONLY for divergent categories

  comparison_report.md   — narrative side-by-side report

Usage:
  python3 scripts/security/compare-stacks.py \
      --left  artifacts/sec-keycloak-spiffe-<ts> \
      --right artifacts/sec-linkerd-<ts> \
      --out   artifacts/sec-compare-<ts>
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

ACCEPTED = {"accepted", "accepted-by-broker", "reachable"}
REJECTED = {"rejected", "broker-rejected", "not-reachable", "blocked"}


def normalise(v) -> str:
    if v is None or (isinstance(v, float) and pd.isna(v)):
        return "N/A"
    if not isinstance(v, str):
        v = str(v)
    if v in ACCEPTED:
        return "ACCEPT"
    if v in REJECTED:
        return "REJECT"
    if v.startswith("reachable("):
        return "ACCEPT"
    return v.upper()


def load_run(d: Path) -> pd.DataFrame:
    jsonl = d / "results.jsonl"
    if not jsonl.exists():
        raise SystemExit(f"missing {jsonl}")
    rows = []
    with jsonl.open() as f:
        for line in f:
            line = line.strip()
            if line:
                rows.append(json.loads(line))
    return pd.DataFrame(rows)


def case_id_sort_key(cid: str) -> tuple[str, int]:
    head = "".join(c for c in cid if c.isalpha())
    tail = "".join(c for c in cid if c.isdigit())
    return (head, int(tail) if tail else 0)


def side_by_side(left: pd.DataFrame, right: pd.DataFrame,
                 left_name: str, right_name: str) -> pd.DataFrame:
    keep = ["case_id", "category", "status", "verdict", "is_attack", "notes"]
    l = left[keep].copy().rename(columns={
        "status":  f"{left_name}_status",
        "verdict": f"{left_name}_verdict",
        "notes":   f"{left_name}_notes",
    })
    r = right[keep].copy().rename(columns={
        "status":  f"{right_name}_status",
        "verdict": f"{right_name}_verdict",
        "notes":   f"{right_name}_notes",
    })
    merged = pd.merge(
        l, r,
        on=["case_id", "category", "is_attack"],
        how="outer",
        suffixes=("", "_r"),
    )
    merged["diverges"] = (
        merged[f"{left_name}_verdict"].map(normalise)
        != merged[f"{right_name}_verdict"].map(normalise)
    )
    merged["sort_key"] = merged["case_id"].map(case_id_sort_key)
    merged = merged.sort_values("sort_key").drop(columns="sort_key").reset_index(drop=True)
    return merged


def category_rates(df: pd.DataFrame) -> pd.DataFrame:
    a = df[df["is_attack"] == 1].copy()
    a["norm"] = a["verdict"].map(normalise)
    g = a.groupby(["category", "stack", "norm"]).size().unstack("norm", fill_value=0)
    for col in ("REJECT", "ACCEPT"):
        if col not in g.columns:
            g[col] = 0
    g["total"] = g[["REJECT", "ACCEPT"]].sum(axis=1)
    g["reject_rate"] = (g["REJECT"] / g["total"].replace(0, np.nan)).round(3)
    g = g.reset_index().pivot(
        index="category", columns="stack",
        values=["REJECT", "ACCEPT", "total", "reject_rate"],
    )
    g.columns = [f"{stack}_{metric}" for metric, stack in g.columns]
    g = g.sort_index()
    return g


def add_delta_column(rates: pd.DataFrame, left_name: str, right_name: str) -> pd.DataFrame:
    rates = rates.copy()
    lcol = f"{left_name}_reject_rate"
    rcol = f"{right_name}_reject_rate"
    if lcol in rates.columns and rcol in rates.columns:
        rates["delta_reject_rate"] = (rates[lcol].fillna(0) - rates[rcol].fillna(0)).round(3)
    return rates


def draw_heatmap(sxs: pd.DataFrame, left_name: str, right_name: str, out_png: Path) -> None:
    def cell(v: str | float) -> int:
        if isinstance(v, float) and pd.isna(v):
            return 0
        n = normalise(v)
        if n == "REJECT":
            return 1
        if n == "ACCEPT":
            return 2
        return 0

    rows = sxs["case_id"].tolist()
    data = np.zeros((len(rows), 2), dtype=int)
    for i, _ in enumerate(rows):
        data[i, 0] = cell(sxs.iloc[i][f"{left_name}_verdict"])
        data[i, 1] = cell(sxs.iloc[i][f"{right_name}_verdict"])

    cmap = plt.matplotlib.colors.ListedColormap(["#d0d0d0", "#3aa757", "#d64545"])
    fig, ax = plt.subplots(figsize=(5.5, max(8, len(rows) * 0.22)))
    ax.imshow(data, aspect="auto", cmap=cmap, vmin=0, vmax=2)
    ax.set_xticks([0, 1])
    ax.set_xticklabels([left_name, right_name], rotation=0)
    ax.set_yticks(range(len(rows)))
    ax.set_yticklabels(rows, fontsize=8)
    ax.set_title("Per-case verdict heatmap\n(green=REJECT, red=ACCEPT, grey=N/A)")
    for i in range(len(rows)):
        for j in (0, 1):
            label = {0: "—", 1: "R", 2: "A"}[data[i, j]]
            ax.text(j, i, label, ha="center", va="center",
                    color="white" if data[i, j] else "black", fontsize=7)
    ax.set_xticks(np.arange(-.5, 2, 1), minor=True)
    ax.set_yticks(np.arange(-.5, len(rows), 1), minor=True)
    ax.grid(which="minor", color="white", linewidth=0.5)
    ax.tick_params(which="minor", bottom=False, left=False)
    plt.tight_layout()
    plt.savefig(out_png, dpi=130)
    plt.close()


def draw_category_bars(rates: pd.DataFrame, left_name: str, right_name: str,
                       out_png: Path, divergent_only: bool = False) -> None:
    lcol = f"{left_name}_reject_rate"
    rcol = f"{right_name}_reject_rate"
    df = rates[[lcol, rcol]].copy().fillna(-0.05)
    if divergent_only:
        diff = (rates[lcol].fillna(0) - rates[rcol].fillna(0)).abs()
        df = df.loc[diff > 0.001]
        if df.empty:
            return
    cats = df.index.tolist()
    x = np.arange(len(cats))
    width = 0.38
    fig, ax = plt.subplots(figsize=(max(8, len(cats) * 1.1), 5))
    b1 = ax.bar(x - width/2, df[lcol].clip(lower=0), width, label=left_name, color="#3066be")
    b2 = ax.bar(x + width/2, df[rcol].clip(lower=0), width, label=right_name, color="#e08e45")
    for bars, col in ((b1, lcol), (b2, rcol)):
        for bar, val in zip(bars, df[col]):
            txt = "n/a" if val < 0 else f"{val:.2f}"
            ax.text(bar.get_x() + bar.get_width()/2,
                    max(bar.get_height(), 0) + 0.02,
                    txt, ha="center", fontsize=8)
    ax.set_xticks(x)
    ax.set_xticklabels(cats, rotation=25, ha="right")
    ax.set_ylim(0, 1.15)
    ax.set_ylabel("reject rate (attacks only)")
    ax.set_title(("Divergent " if divergent_only else "")
                 + f"Attack reject rate by category — {left_name} vs {right_name}")
    ax.legend()
    plt.tight_layout()
    plt.savefig(out_png, dpi=130)
    plt.close()


def write_report(sxs: pd.DataFrame, rates: pd.DataFrame,
                 left_name: str, right_name: str, out_md: Path) -> None:
    div = sxs[sxs["diverges"]]
    keycloak_only = sxs[sxs[f"{right_name}_verdict"].isna()]
    linkerd_only  = sxs[sxs[f"{left_name}_verdict"].isna()]

    def fmt_rate(v):
        return "n/a" if pd.isna(v) else f"{v:.3f}"

    lines: list[str] = []
    lines.append(f"# Security probe comparison — {left_name} vs {right_name}\n")
    lines.append("Generated by `scripts/security/compare-stacks.py`.\n")

    lines.append("\n## 1. Case-count summary\n")
    lines.append(f"- Total cases per stack: `{(sxs[f'{left_name}_verdict'].notna()).sum()}` ({left_name}), `{(sxs[f'{right_name}_verdict'].notna()).sum()}` ({right_name})")
    lines.append(f"- Cases that diverge: **{len(div)}**")
    lines.append(f"- Cases only on `{left_name}`: {len(keycloak_only)}")
    lines.append(f"- Cases only on `{right_name}`: {len(linkerd_only)}\n")

    lines.append("\n## 2. Per-category attack reject rate\n")
    cat = rates.reset_index()
    lcol = f"{left_name}_reject_rate"
    rcol = f"{right_name}_reject_rate"
    lines.append(f"| category | {left_name} (REJECT/total) | rate | {right_name} (REJECT/total) | rate | Δrate |")
    lines.append("|---|---:|---:|---:|---:|---:|")
    for _, r in cat.iterrows():
        c = r["category"]
        lr = r.get(f"{left_name}_REJECT", float("nan"))
        lt = r.get(f"{left_name}_total",  float("nan"))
        rr = r.get(f"{right_name}_REJECT", float("nan"))
        rt = r.get(f"{right_name}_total",  float("nan"))
        delta = r.get("delta_reject_rate", float("nan"))
        lpair = "n/a" if pd.isna(lt) else f"{int(lr)}/{int(lt)}"
        rpair = "n/a" if pd.isna(rt) else f"{int(rr)}/{int(rt)}"
        lines.append(
            f"| {c} | {lpair} | {fmt_rate(r.get(lcol))} | "
            f"{rpair} | {fmt_rate(r.get(rcol))} | {fmt_rate(delta)} |"
        )

    def safe_note(*candidates) -> str:
        for c in candidates:
            if isinstance(c, str) and c:
                return c.split("—")[0].strip()[:60]
        return ""

    def safe_cell(v) -> str:
        if v is None or (isinstance(v, float) and pd.isna(v)):
            return "—"
        return str(v)

    if not div.empty:
        lines.append("\n\n## 3. Divergent cases (different verdict between the two stacks)\n")
        lines.append(f"| case_id | category | is_attack | {left_name} | {right_name} | notes |")
        lines.append("|---|---|:---:|---|---|---|")
        for _, r in div.iterrows():
            note = safe_note(r.get(f"{left_name}_notes"), r.get(f"{right_name}_notes"))
            lines.append(
                f"| {r['case_id']} | {r['category']} | {int(r['is_attack'])} | "
                f"{safe_cell(r.get(f'{left_name}_status'))} {safe_cell(r.get(f'{left_name}_verdict'))} | "
                f"{safe_cell(r.get(f'{right_name}_status'))} {safe_cell(r.get(f'{right_name}_verdict'))} | "
                f"{note} |"
            )

    if not keycloak_only.empty:
        lines.append(f"\n\n## 4. Cases unique to `{left_name}`\n")
        lines.append(f"| case_id | category | is_attack | status | verdict | notes |")
        lines.append("|---|---|:---:|---|---|---|")
        for _, r in keycloak_only.iterrows():
            note = safe_note(r.get(f"{left_name}_notes"))
            lines.append(
                f"| {r['case_id']} | {r['category']} | {int(r['is_attack'])} | "
                f"{safe_cell(r.get(f'{left_name}_status'))} | {safe_cell(r.get(f'{left_name}_verdict'))} | {note} |"
            )

    if not linkerd_only.empty:
        lines.append(f"\n\n## 5. Cases unique to `{right_name}`\n")
        lines.append(f"| case_id | category | is_attack | status | verdict | notes |")
        lines.append("|---|---|:---:|---|---|---|")
        for _, r in linkerd_only.iterrows():
            note = safe_note(r.get(f"{right_name}_notes"))
            lines.append(
                f"| {r['case_id']} | {r['category']} | {int(r['is_attack'])} | "
                f"{safe_cell(r.get(f'{right_name}_status'))} | {safe_cell(r.get(f'{right_name}_verdict'))} | {note} |"
            )

    lines.append("\n\n## 6. Artifacts\n")
    lines.append("- `comparison.xlsx` — side_by_side / category_rates / divergences_only / keycloak_only / raw")
    lines.append("- `comparison_heatmap.png` — case-level verdict heatmap")
    lines.append("- `comparison_bars.png` — category × stack reject-rate bars")
    lines.append("- `divergence_bars.png` — same bars filtered to divergent categories")
    out_md.write_text("\n".join(lines) + "\n")


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--left",  required=True, type=Path)
    ap.add_argument("--right", required=True, type=Path)
    ap.add_argument("--out",   required=True, type=Path)
    args = ap.parse_args()

    args.out.mkdir(parents=True, exist_ok=True)
    left  = load_run(args.left)
    right = load_run(args.right)
    left_name  = left["stack"].iloc[0]
    right_name = right["stack"].iloc[0]
    print(f"left={left_name} ({len(left)} cases) right={right_name} ({len(right)} cases)")

    sxs = side_by_side(left, right, left_name, right_name)
    rates = category_rates(pd.concat([left, right], ignore_index=True))
    rates = add_delta_column(rates, left_name, right_name)

    xlsx = args.out / "comparison.xlsx"
    with pd.ExcelWriter(xlsx, engine="openpyxl") as w:
        sxs.drop(columns=[f"{left_name}_notes", f"{right_name}_notes"]).to_excel(w, sheet_name="side_by_side", index=False)
        rates.to_excel(w, sheet_name="category_rates")
        sxs[sxs["diverges"]].drop(columns=[f"{left_name}_notes", f"{right_name}_notes"]).to_excel(w, sheet_name="divergences_only", index=False)
        sxs[sxs[f"{right_name}_verdict"].isna()].to_excel(w, sheet_name=f"only_{left_name}", index=False)
        pd.concat([left, right], ignore_index=True).to_excel(w, sheet_name="raw", index=False)
    print(f"wrote: {xlsx}")

    draw_heatmap(sxs, left_name, right_name, args.out / "comparison_heatmap.png")
    print(f"wrote: {args.out / 'comparison_heatmap.png'}")

    draw_category_bars(rates, left_name, right_name, args.out / "comparison_bars.png")
    print(f"wrote: {args.out / 'comparison_bars.png'}")

    draw_category_bars(rates, left_name, right_name, args.out / "divergence_bars.png",
                       divergent_only=True)
    print(f"wrote: {args.out / 'divergence_bars.png'}")

    write_report(sxs, rates, left_name, right_name, args.out / "comparison_report.md")
    print(f"wrote: {args.out / 'comparison_report.md'}")


if __name__ == "__main__":
    main()
