#!/usr/bin/env python3
"""Export security-suite results into XLSX files (matching the perf-experiment
naming style: each metric → one xlsx with long-form data + 統計摘要 sheet).

Reads `security-summary.json` from the given <stamp-dir> and writes alongside:
    <stamp-dir>/攻擊矩陣.xlsx
    <stamp-dir>/各層防禦統計.xlsx
    <stamp-dir>/拒絕原因分類.xlsx
    <stamp-dir>/偵測延遲.xlsx
    <stamp-dir>/防禦態勢.xlsx
    <stamp-dir>/summary.xlsx

Usage:
    python export_security_xlsx.py artifacts/security-<STAMP>
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import pandas as pd

CATEGORY_ORDER = [
    "token-forgery", "trust-domain", "chain-attack",
    "time-attack", "replay", "mtls", "amqp-inject", "shm-tamper", "baseline",
]
PROFILE_ORDER = ["A-baseline", "B-mtls-only", "C-lsvid-only", "D-full-zt"]


def df_from_cases(summary: dict) -> pd.DataFrame:
    rows = []
    for c in summary.get("cases", []):
        o = c.get("outcome", {}) or {}
        rows.append({
            "case_id":          c.get("case_id"),
            "category":         c.get("category"),
            "layer_expected":   c.get("layer_expected"),
            "profile":          c.get("profile"),
            "stage":            c.get("stage"),
            "status":           o.get("status"),
            "is_expected":      bool(o.get("is_expected")),
            "rejected_by":      o.get("rejected_by"),
            "reject_reason":    o.get("reject_reason"),
            "detect_latency_us": o.get("detect_latency_us"),
        })
    return pd.DataFrame(rows)


def outcome_label(row: pd.Series) -> str:
    if row["status"] == "rejected" and row["is_expected"]:
        return "rejected_as_expected"
    if row["status"] == "accepted" and not row["is_expected"]:
        return "accepted_violation"
    if row["status"] == "accepted" and row["is_expected"]:
        return "accepted_expected"
    if row["status"] == "error":
        return "error"
    return "other"


def write_attack_matrix(df: pd.DataFrame, out: Path) -> None:
    df = df.copy()
    df["outcome_label"] = df.apply(outcome_label, axis=1)

    pivot = df.pivot_table(
        index="case_id", columns="profile", values="outcome_label",
        aggfunc=lambda s: s.iloc[0] if len(s) else "",
    )
    case_order = [c for c in df["case_id"].drop_duplicates().tolist() if c in pivot.index]
    pivot = pivot.reindex(
        index=case_order, columns=[p for p in PROFILE_ORDER if p in pivot.columns]
    )
    pivot.index.name = "case_id"

    summary = (
        df.groupby(["profile", "outcome_label"]).size()
        .unstack(fill_value=0)
        .reindex([p for p in PROFILE_ORDER if p in df["profile"].unique()])
    )
    summary.index.name = "profile"

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        pivot.to_excel(xw, sheet_name="攻擊矩陣")
        summary.to_excel(xw, sheet_name="統計摘要")


def write_defense_by_layer(df: pd.DataFrame, out: Path) -> None:
    rej = df[df["status"] == "rejected"].copy()
    if rej.empty:
        _write_empty(out, "各層防禦", "no rejected rows in dataset")
        return
    rej["layer"] = rej["rejected_by"].fillna("unknown")
    counts = (
        rej.groupby(["profile", "layer"]).size()
        .unstack(fill_value=0)
        .reindex([p for p in PROFILE_ORDER if p in rej["profile"].unique()])
    )
    counts.index.name = "profile"

    long_form = rej[["profile", "layer", "case_id", "category", "stage"]].sort_values(
        ["profile", "layer", "case_id"]
    )

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        counts.to_excel(xw, sheet_name="各層拒絕計數")
        long_form.to_excel(xw, sheet_name="逐筆明細", index=False)


def write_reject_reason(df: pd.DataFrame, out: Path) -> None:
    reasons = df.loc[df["reject_reason"].notna()].copy()
    if reasons.empty:
        _write_empty(out, "拒絕原因", "no reject_reason values")
        return
    counts = (
        reasons["reject_reason"].value_counts()
        .rename_axis("reject_reason").reset_index(name="count")
    )
    by_profile = (
        reasons.groupby(["reject_reason", "profile"]).size()
        .unstack(fill_value=0)
        .reindex(columns=[p for p in PROFILE_ORDER if p in reasons["profile"].unique()])
    )

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        counts.to_excel(xw, sheet_name="原因彙總", index=False)
        by_profile.to_excel(xw, sheet_name="profile×原因")


def write_detect_latency(df: pd.DataFrame, out: Path) -> None:
    lat = df.loc[df["detect_latency_us"].fillna(0) > 0,
                 ["case_id", "category", "profile", "detect_latency_us"]].copy()
    if lat.empty:
        _write_empty(out, "偵測延遲", "no latency samples")
        return

    long = lat.sort_values(["category", "profile", "detect_latency_us"]).reset_index(drop=True)
    stats_rows = []
    for cat, sub in lat.groupby("category"):
        s = sub["detect_latency_us"].astype(float)
        stats_rows.append({
            "category": cat,
            "count": int(s.count()),
            "mean":  float(s.mean()),
            "p50":   float(s.quantile(0.5)),
            "p90":   float(s.quantile(0.9)),
            "p95":   float(s.quantile(0.95)),
            "p99":   float(s.quantile(0.99)),
            "min":   float(s.min()),
            "max":   float(s.max()),
        })
    stats_df = pd.DataFrame(stats_rows)

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        long.to_excel(xw, sheet_name="逐筆延遲(µs)", index=False)
        stats_df.to_excel(xw, sheet_name="統計摘要", index=False)


def write_posture(df: pd.DataFrame, out: Path) -> None:
    axes_cats = ["token-forgery", "trust-domain", "chain-attack", "time-attack", "replay"]
    profiles = [p for p in PROFILE_ORDER if p in df["profile"].unique()]
    rows = []
    for p in profiles:
        p_df = df[df["profile"] == p]
        for cat in axes_cats:
            cat_df = p_df[p_df["category"] == cat]
            total = len(cat_df)
            rejected = int(((cat_df["status"] == "rejected") & cat_df["is_expected"]).sum())
            score = (rejected / total) if total else float("nan")
            rows.append({
                "profile": p, "category": cat,
                "total": total, "rejected_as_expected": rejected,
                "coverage_rate": score,
            })
    posture = pd.DataFrame(rows)
    pivot = posture.pivot_table(
        index="profile", columns="category", values="coverage_rate"
    ).reindex(index=profiles, columns=axes_cats)

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        posture.to_excel(xw, sheet_name="逐項覆蓋率", index=False)
        pivot.to_excel(xw, sheet_name="profile×category")


def write_summary(df: pd.DataFrame, out: Path) -> None:
    df = df.copy()
    df["outcome_label"] = df.apply(outcome_label, axis=1)

    profile_summary = (
        df.groupby("profile")
        .agg(total=("case_id", "size"),
             rejected_as_expected=("outcome_label", lambda s: (s == "rejected_as_expected").sum()),
             accepted_violations=("outcome_label", lambda s: (s == "accepted_violation").sum()),
             accepted_expected=("outcome_label", lambda s: (s == "accepted_expected").sum()),
             errors=("outcome_label", lambda s: (s == "error").sum()))
        .reindex([p for p in PROFILE_ORDER if p in df["profile"].unique()])
    )
    profile_summary["security_pass_rate"] = (
        profile_summary["rejected_as_expected"] /
        (profile_summary["total"] - profile_summary["accepted_expected"]).replace(0, pd.NA)
    )

    category_summary = (
        df.groupby("category")
        .agg(total=("case_id", "size"),
             rejected_as_expected=("outcome_label", lambda s: (s == "rejected_as_expected").sum()),
             accepted_violations=("outcome_label", lambda s: (s == "accepted_violation").sum()))
    )
    category_summary["coverage_rate"] = (
        category_summary["rejected_as_expected"] /
        (category_summary["total"] - (
            df.groupby("category").apply(lambda d: (d.apply(outcome_label, axis=1) == "accepted_expected").sum())
        )).replace(0, pd.NA)
    )

    stage_summary = (
        df.groupby("stage")
        .agg(total=("case_id", "size"),
             rejected_as_expected=("outcome_label", lambda s: (s == "rejected_as_expected").sum()),
             accepted_violations=("outcome_label", lambda s: (s == "accepted_violation").sum()))
    )

    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        profile_summary.to_excel(xw, sheet_name="profile摘要")
        category_summary.to_excel(xw, sheet_name="category摘要")
        stage_summary.to_excel(xw, sheet_name="stage摘要")


def _write_empty(out: Path, sheet: str, msg: str) -> None:
    with pd.ExcelWriter(out, engine="openpyxl") as xw:
        pd.DataFrame({"note": [msg]}).to_excel(xw, sheet_name=sheet, index=False)


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    stamp_dir = Path(sys.argv[1])
    summary_path = stamp_dir / "security-summary.json"
    if not summary_path.exists():
        print(f"[xlsx] missing {summary_path}", file=sys.stderr)
        return 1

    summary = json.loads(summary_path.read_text())
    df = df_from_cases(summary)
    if df.empty:
        print("[xlsx] no cases — aborting", file=sys.stderr)
        return 1

    write_attack_matrix(df, stamp_dir / "攻擊矩陣.xlsx")
    write_defense_by_layer(df, stamp_dir / "各層防禦統計.xlsx")
    write_reject_reason(df, stamp_dir / "拒絕原因分類.xlsx")
    write_detect_latency(df, stamp_dir / "偵測延遲.xlsx")
    write_posture(df, stamp_dir / "防禦態勢.xlsx")
    write_summary(df, stamp_dir / "summary.xlsx")

    print(f"[xlsx] OK → {stamp_dir}", file=sys.stderr)
    print(f"[xlsx] cases={len(df)} profiles={df['profile'].nunique()} "
          f"categories={df['category'].nunique()}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
