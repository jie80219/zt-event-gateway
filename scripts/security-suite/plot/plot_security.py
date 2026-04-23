#!/usr/bin/env python3
"""Render security-suite results into 8 figures (PNG).

Usage:
    python plot_security.py artifacts/security-<STAMP>

Reads `security-summary.json` in the given directory, optionally pulls
`ablation-summary.json` from the newest `experiment-*` neighbor folder
for the perf-vs-security tradeoff plot, and writes PNGs into
`<stamp-dir>/experiment-png/`.
"""

from __future__ import annotations

import json
import math
import sys
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any

import matplotlib
matplotlib.use("Agg")  # headless
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
import seaborn as sns

sns.set_theme(style="whitegrid", context="paper", font_scale=1.05)


# ── Helpers ──────────────────────────────────────────────────────────────────

CATEGORY_ORDER = [
    "token-forgery", "trust-domain", "chain-attack",
    "time-attack", "replay", "mtls", "amqp-inject", "shm-tamper", "baseline",
]

PROFILE_ORDER = ["A-baseline", "B-mtls-only", "C-lsvid-only", "D-full-zt", "E-oauth2-bearer"]


def load_summary(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text())
    return data


def find_neighbor_ablation(stamp_dir: Path) -> Path | None:
    """Find the newest artifacts/experiment-* folder's ablation-summary.json."""
    parent = stamp_dir.parent
    candidates = sorted(
        (p for p in parent.glob("experiment-*") if (p / "ablation-summary.json").exists()),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    if candidates:
        return candidates[0] / "ablation-summary.json"
    return None


def df_from_cases(summary: dict[str, Any]) -> pd.DataFrame:
    rows = []
    for c in summary.get("cases", []):
        o = c.get("outcome", {}) or {}
        rows.append({
            "case_id":        c.get("case_id"),
            "category":       c.get("category"),
            "layer_expected": c.get("layer_expected"),
            "profile":        c.get("profile"),
            "stage":          c.get("stage"),
            "status":         o.get("status"),
            "is_expected":    bool(o.get("is_expected")),
            "rejected_by":    o.get("rejected_by"),
            "reject_reason":  o.get("reject_reason"),
            "detect_latency_us": o.get("detect_latency_us"),
        })
    return pd.DataFrame(rows)


def outcome_code(row: pd.Series) -> int:
    """0 = rejected-as-expected (good), 1 = accepted-violation (bad), 2 = accepted-expected (baseline pass), 3 = error/other."""
    if row["status"] == "rejected" and row["is_expected"]:
        return 0
    if row["status"] == "accepted" and not row["is_expected"]:
        return 1
    if row["status"] == "accepted" and row["is_expected"]:
        return 2
    return 3


# ── S01: attack matrix heatmap ──────────────────────────────────────────────

def plot_attack_matrix(df: pd.DataFrame, out: Path) -> None:
    df = df.copy()
    df["code"] = df.apply(outcome_code, axis=1)
    pivot = df.pivot_table(index="case_id", columns="profile", values="code", aggfunc="min")
    # Preserve logical case order
    case_order = [c for c in df["case_id"].drop_duplicates().tolist() if c in pivot.index]
    pivot = pivot.reindex(index=case_order, columns=[p for p in PROFILE_ORDER if p in pivot.columns])

    cmap = matplotlib.colors.ListedColormap(["#2ca02c", "#d62728", "#1f77b4", "#7f7f7f"])
    fig, ax = plt.subplots(figsize=(7.5, max(5, 0.3 * len(pivot))))
    sns.heatmap(
        pivot.fillna(3), cmap=cmap, vmin=0, vmax=3,
        cbar_kws={"ticks": [0.4, 1.2, 2.0, 2.8], "label": ""},
        linewidths=0.5, linecolor="white", ax=ax,
        annot=False,
    )
    cbar = ax.collections[0].colorbar
    cbar.set_ticklabels(["rejected (good)", "accepted violation", "accepted (baseline)", "n/a"])
    ax.set_title("Attack Matrix: case × profile")
    ax.set_xlabel("Profile")
    ax.set_ylabel("Attack case")
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S02: defense-by-layer stacked bar ────────────────────────────────────────

def plot_defense_by_layer(df: pd.DataFrame, out: Path) -> None:
    rej = df[df["status"] == "rejected"].copy()
    if rej.empty:
        _empty(out, "no rejected rows — skipping defense-by-layer")
        return
    rej["layer"] = rej["rejected_by"].fillna("unknown")
    ct = rej.groupby(["profile", "layer"]).size().unstack(fill_value=0)
    ct = ct.reindex([p for p in PROFILE_ORDER if p in ct.index])

    fig, ax = plt.subplots(figsize=(8, 5))
    ct.plot(kind="bar", stacked=True, ax=ax, colormap="tab20")
    ax.set_title("Which layer rejected each attack (count per profile)")
    ax.set_xlabel("Profile")
    ax.set_ylabel("# of rejected attacks")
    ax.legend(title="Layer", bbox_to_anchor=(1.02, 1), loc="upper left")
    plt.xticks(rotation=0)
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S03: reject-reason taxonomy bar ──────────────────────────────────────────

def plot_reject_reason(df: pd.DataFrame, out: Path) -> None:
    reasons = df.loc[df["reject_reason"].notna(), "reject_reason"]
    if reasons.empty:
        _empty(out, "no reject_reason values")
        return
    counts = reasons.value_counts().sort_values(ascending=True)
    fig, ax = plt.subplots(figsize=(7, max(4, 0.35 * len(counts))))
    counts.plot(kind="barh", color=sns.color_palette("viridis", n_colors=len(counts)), ax=ax)
    ax.set_title("Rejection reason taxonomy (all profiles)")
    ax.set_xlabel("# of occurrences")
    for i, v in enumerate(counts.values):
        ax.text(v + 0.1, i, str(v), va="center")
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S04: detection latency by category (boxplot) ─────────────────────────────

def plot_detect_latency(df: pd.DataFrame, out: Path) -> None:
    lat = df.loc[df["detect_latency_us"].fillna(0) > 0, ["category", "detect_latency_us"]].copy()
    if lat.empty:
        _empty(out, "no latency samples")
        return
    cats = [c for c in CATEGORY_ORDER if c in lat["category"].unique()]
    fig, ax = plt.subplots(figsize=(8, 5))
    sns.boxplot(
        data=lat, x="category", y="detect_latency_us", order=cats,
        hue="category", ax=ax, palette="pastel", showfliers=False, legend=False,
    )
    sns.stripplot(data=lat, x="category", y="detect_latency_us", order=cats,
                  ax=ax, size=3, alpha=0.5, color="#333")
    ax.set_yscale("log")
    ax.set_title("Detection latency by attack category (log µs)")
    ax.set_xlabel("Attack category")
    ax.set_ylabel("Detection latency (µs, log)")
    plt.xticks(rotation=25, ha="right")
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S05: profile security posture radar ──────────────────────────────────────

def plot_posture_radar(df: pd.DataFrame, out: Path) -> None:
    axes_cats = ["token-forgery", "trust-domain", "chain-attack", "time-attack", "replay"]
    profiles = [p for p in PROFILE_ORDER if p in df["profile"].unique()]
    if not profiles:
        _empty(out, "no profiles")
        return

    scores: dict[str, list[float]] = {}
    for p in profiles:
        p_df = df[df["profile"] == p]
        row = []
        for cat in axes_cats:
            cat_df = p_df[p_df["category"] == cat]
            if cat_df.empty:
                row.append(0.0)
                continue
            good = (cat_df["status"] == "rejected") & cat_df["is_expected"]
            row.append(float(good.sum()) / max(1, len(cat_df)))
        scores[p] = row

    # radar setup
    N = len(axes_cats)
    angles = np.linspace(0, 2 * math.pi, N, endpoint=False).tolist()
    angles += angles[:1]

    fig, ax = plt.subplots(figsize=(6, 6), subplot_kw=dict(polar=True))
    colors = sns.color_palette("tab10", n_colors=len(profiles))
    for (p, row), c in zip(scores.items(), colors):
        vals = row + row[:1]
        ax.plot(angles, vals, color=c, linewidth=2, label=p)
        ax.fill(angles, vals, color=c, alpha=0.15)

    ax.set_xticks(angles[:-1])
    ax.set_xticklabels(axes_cats)
    ax.set_ylim(0, 1.05)
    ax.set_yticks([0.25, 0.5, 0.75, 1.0])
    ax.set_yticklabels(["25%", "50%", "75%", "100%"])
    ax.set_title("Security coverage radar (per category, % of attacks rejected)")
    ax.legend(loc="upper right", bbox_to_anchor=(1.35, 1.1))
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S06: rotation race timeline ─────────────────────────────────────────────

def plot_rotation_timeline(cases: list[dict], out: Path) -> None:
    m03 = next(
        (c for c in cases if c.get("case_id") == "M03" and (c.get("outcome") or {}).get("timings")),
        None,
    )
    if m03 is None:
        _empty(out, "no M03 rotation data")
        return
    timings = m03["outcome"]["timings"]
    trigger = m03["outcome"]["rotation_trigger_us"]
    xs = [(t["start_us"] - trigger) / 1e6 for t in timings]  # seconds from rotation
    ys = [t["latency_us"] / 1000 for t in timings]           # ms
    colors = ["#2ca02c" if t["status"] == "accepted" else ("#d62728" if t["status"] == "rejected" else "#7f7f7f") for t in timings]

    fig, ax = plt.subplots(figsize=(9, 4.5))
    ax.scatter(xs, ys, c=colors, s=40, edgecolor="white", linewidth=0.5)
    ax.axvline(0, color="k", linestyle="--", linewidth=1)
    ax.text(0.02, ax.get_ylim()[1] * 0.9, "rotation trigger", fontsize=8, color="k")
    ax.set_title("SVID rotation race timeline (M03)")
    ax.set_xlabel("seconds relative to rotation trigger")
    ax.set_ylabel("request latency (ms)")
    from matplotlib.patches import Patch
    ax.legend(handles=[Patch(color="#2ca02c", label="accepted"),
                       Patch(color="#d62728", label="rejected"),
                       Patch(color="#7f7f7f", label="error")],
              loc="upper right")
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S07: AMQP bypass outcome grouped bar ────────────────────────────────────

def plot_amqp_bypass(df: pd.DataFrame, out: Path) -> None:
    sub = df[df["category"] == "amqp-inject"].copy()
    if sub.empty:
        _empty(out, "no amqp-inject cases")
        return
    sub["outcome_label"] = sub.apply(
        lambda r: "rejected" if r["status"] == "rejected"
        else ("accepted (violation)" if r["status"] == "accepted" and not r["is_expected"] else "accepted (baseline)"),
        axis=1,
    )
    ct = sub.groupby(["profile", "outcome_label"]).size().unstack(fill_value=0)
    ct = ct.reindex([p for p in PROFILE_ORDER if p in ct.index])

    fig, ax = plt.subplots(figsize=(7, 4.5))
    ct.plot(kind="bar", ax=ax, color=["#2ca02c", "#d62728", "#1f77b4"][: len(ct.columns)])
    ax.set_title("AMQP / SHM bypass attempts per profile")
    ax.set_xlabel("Profile")
    ax.set_ylabel("# of cases")
    plt.xticks(rotation=0)
    ax.legend(title="Outcome")
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── S08: security vs perf tradeoff scatter ──────────────────────────────────

def plot_security_vs_perf(summary: dict, ablation_path: Path | None, out: Path) -> None:
    coverage = {}
    for p in summary["profiles"]:
        stats = summary["by_profile"].get(p, {})
        total = max(1, stats.get("total", 0))
        coverage[p] = stats.get("rejected_as_expected", 0) / total

    # Default perf values if ablation is absent — just use coverage only.
    perf_p95 = {p: None for p in coverage}
    if ablation_path and ablation_path.exists():
        try:
            ab = json.loads(ablation_path.read_text())
            # ab is {"scenarios": [...]} or {"A": {...}, "B": {...}} — be defensive
            for key, val in (ab.get("profiles") or ab).items():
                if isinstance(val, dict):
                    p95 = val.get("latency_p95_ms") or val.get("p95_ms")
                    for name in coverage:
                        if name.startswith(key[0]):
                            perf_p95[name] = p95
        except Exception as e:
            print(f"[warn] could not parse ablation: {e}")

    fig, ax = plt.subplots(figsize=(7, 5))
    xs = []
    ys = []
    labels = []
    for p in coverage:
        p95 = perf_p95.get(p)
        if p95 is None:
            continue
        xs.append(p95)
        ys.append(coverage[p] * 100)
        labels.append(p)
    if xs:
        ax.scatter(xs, ys, s=160, alpha=0.8, c=sns.color_palette("tab10", n_colors=len(xs)))
        for x, y, l in zip(xs, ys, labels):
            ax.annotate(l, (x, y), textcoords="offset points", xytext=(8, 8), fontsize=9)
        ax.set_xlabel("p95 latency (ms) — from ablation study")
        ax.set_ylabel("security coverage (%)")
    else:
        # Fallback: just a bar chart of coverage
        names = list(coverage)
        vals = [coverage[n] * 100 for n in names]
        ax.bar(names, vals, color=sns.color_palette("tab10", n_colors=len(names)))
        ax.set_ylabel("security coverage (%)")
        ax.set_xlabel("profile")

    ax.set_title("Security coverage vs performance tradeoff")
    ax.set_ylim(0, 105)
    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


def _empty(out: Path, reason: str) -> None:
    fig, ax = plt.subplots(figsize=(6, 3))
    ax.text(0.5, 0.5, f"(no data)\n{reason}", ha="center", va="center", fontsize=11)
    ax.axis("off")
    fig.savefig(out, dpi=120)
    plt.close(fig)


# ── S09: D-vs-E cross-architecture defense coverage heatmap ─────────────────

def plot_d_vs_e_coverage(df: pd.DataFrame, out: Path) -> None:
    """Figure 9 — side-by-side D/E heatmap quantifying the workload-identity
    vs OAuth2-Bearer defense gap. See docs/experiment-comparison-targets.md §2.6.

    The outcome code mirrors plot_attack_matrix but adds a new reason hint:
    Profile-E structural_gap rows are shown as "accepted violation" (red) so
    reviewers can see exactly which attack classes OAuth2 Bearer cannot cover.
    """
    df = df.copy()
    df["code"] = df.apply(outcome_code, axis=1)

    wanted = ["D-full-zt", "E-oauth2-bearer"]
    present = [p for p in wanted if p in df["profile"].unique()]
    if len(present) < 2:
        _empty(out, f"need both D-full-zt and E-oauth2-bearer, have {present}")
        return

    pivot = df[df["profile"].isin(present)].pivot_table(
        index="case_id", columns="profile", values="code", aggfunc="min"
    )
    case_order = [c for c in df["case_id"].drop_duplicates().tolist() if c in pivot.index]
    pivot = pivot.reindex(index=case_order, columns=present)

    cmap = matplotlib.colors.ListedColormap(["#2ca02c", "#d62728", "#1f77b4", "#7f7f7f"])
    fig, ax = plt.subplots(figsize=(5.5, max(6, 0.32 * len(pivot))))
    sns.heatmap(
        pivot.fillna(3), cmap=cmap, vmin=0, vmax=3,
        cbar_kws={"ticks": [0.4, 1.2, 2.0, 2.8], "label": ""},
        linewidths=0.6, linecolor="white", ax=ax, annot=False,
    )
    cbar = ax.collections[0].colorbar
    cbar.set_ticklabels(["rejected (good)", "accepted violation", "accepted (baseline)", "n/a"])
    ax.set_title("Workload-identity vs OAuth2-Bearer: defense gap per case")
    ax.set_xlabel("Profile")
    ax.set_ylabel("Attack case")

    # Coverage summary footer
    def coverage(p: str) -> float:
        sub = df[(df["profile"] == p) & (df["category"] != "baseline")]
        sub = sub[sub["status"].isin(["accepted", "rejected"])]
        if sub.empty:
            return 0.0
        good = ((sub["status"] == "rejected") & sub["is_expected"]).sum()
        return good / len(sub) * 100
    d_pct = coverage("D-full-zt")
    e_pct = coverage("E-oauth2-bearer")
    ax.text(
        1.08, -0.06,
        f"coverage — D: {d_pct:.0f}% · E: {e_pct:.0f}% · gap: {d_pct - e_pct:+.0f}pp",
        transform=ax.transAxes, ha="right", va="top", fontsize=9, style="italic",
    )

    plt.tight_layout()
    fig.savefig(out, dpi=150)
    plt.close(fig)


# ── Main ────────────────────────────────────────────────────────────────────

def main() -> int:
    if len(sys.argv) < 2:
        print("usage: plot_security.py <artifacts/security-STAMP>", file=sys.stderr)
        return 2
    stamp_dir = Path(sys.argv[1]).resolve()
    summary_path = stamp_dir / "security-summary.json"
    if not summary_path.exists():
        print(f"[err] {summary_path} not found", file=sys.stderr)
        return 2

    summary = load_summary(summary_path)
    df = df_from_cases(summary)
    out_dir = stamp_dir / "experiment-png"
    out_dir.mkdir(exist_ok=True)

    ablation = find_neighbor_ablation(stamp_dir)
    if ablation:
        print(f"[info] using ablation data from {ablation}")

    plot_attack_matrix(df, out_dir / "01-attack-matrix.png")
    plot_defense_by_layer(df, out_dir / "02-defense-by-layer.png")
    plot_reject_reason(df, out_dir / "03-reject-reason-taxonomy.png")
    plot_detect_latency(df, out_dir / "04-detect-latency-by-category.png")
    plot_posture_radar(df, out_dir / "05-profile-security-posture.png")
    plot_rotation_timeline(summary.get("cases", []), out_dir / "06-rotation-race-timeline.png")
    plot_amqp_bypass(df, out_dir / "07-amqp-bypass-outcome.png")
    plot_security_vs_perf(summary, ablation, out_dir / "08-security-vs-perf-tradeoff.png")
    plot_d_vs_e_coverage(df, out_dir / "09-profile-d-vs-e-coverage.png")

    print(f"[plot] wrote 9 figures to {out_dir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
