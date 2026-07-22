#!/usr/bin/env python3
"""Aggregate the attack-matrix CSVs of two stacks into ch5 §5 outputs.

Default pairing is SPIFFE+Keycloak+LSVID vs Vault PKI (override with --stacks).

Inputs (under --in DIR):
    raw/<stack-a>.csv
    raw/<stack-b>.csv

Outputs (written next to inputs):
    summary.csv                 per-category × stack rejection ratio (+ sample n)
    summary_by_class.csv        per-capability-class × stack rejection ratio
    comparison_bars.png         grouped bars per category (n annotated)
    divergence_bars.png         same, only categories where the stacks disagree
    capability_class_bars.png   grouped bars per CAPABILITY CLASS — the headline
                                figure: architecture (lsvid/spiffe) vs the now
                                CONTROLLED container-config class
    comparison_heatmap.png      case_id × stack heatmap (REJECT/ACCEPT/NA)
    cve-mapping.md              15-CVE backbone → attack-category table

Methodology notes (why this script is shaped the way it is)
-----------------------------------------------------------
1.  METRIC NAME.  Each category holds only 2–4 test cases, so the figure a
    stack earns is NOT a statistical "attack coverage %". It is a *test-case
    rejection ratio* (測試案例拒絕比例) = rejected / scored, and is reported
    with the raw n so the reader can see that e.g. 0.75 vs 0.0 is "3 of 4 vs
    0 of 4 cases", i.e. a 3-case difference — not a population estimate.

2.  CONTAINER-CONFIG IS A CONTROLLED VARIABLE.  The `config-audit`, `lib-hijack`
    and `port-exposure` cases measure the *container deployment profile*
    (read_only / tmpfs noexec / cap_drop ALL / no-new-privileges), NOT the
    identity architecture. Both stacks now apply the identical hardening
    profile to their identity/secret sidecars, so any residual divergence in
    this class is a deployment choice, not evidence that "SPIFFE > Vault".
    These cases are grouped under capability_class = `container-config` and
    are reported SEPARATELY from the architectural classes so they cannot be
    conflated into an architecture claim.

3.  CAPABILITY CLASSES.  Every case is tagged into exactly one class so the
    three effects the user asked to separate stay separate:
        lsvid            — capability that exists ONLY because of the nested
                           LSVID chain (L0→L1→Ln): forgery/replay detection,
                           blast-radius containment via per-hop verification.
        spiffe           — capability from SPIFFE X.509-SVID / mTLS peer auth
                           (e.g. forged-SVID direct call blocked at handshake).
        container-config — container hardening / deployment surface (CONTROLLED
                           confound; identical on both stacks by construction).
        shared-baseline  — defenses BOTH stacks have (Keycloak ingress AuthN,
                           service-level authz, canonical-envelope validation,
                           central-authority blast radius). Not a differentiator.
        observability    — audit-trail / log-visibility gaps (informational).

Usage:
    python3 scripts/security/compare-stacks.py --in artifacts/sec-compare-...  \
        --stacks spiffe-keycloak,vault \
        --labels "SPIFFE+Keycloak+LSVID,Vault PKI"
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
from matplotlib import font_manager

# Labels carry CJK (測試案例拒絕比例 …). Pick the first CJK-capable font that is
# actually installed so thesis figures render the glyphs instead of tofu boxes.
_CJK_CANDIDATES = ["PingFang TC", "PingFang SC", "Heiti TC", "Hiragino Sans GB",
                   "Arial Unicode MS", "Microsoft JhengHei", "Noto Sans CJK TC",
                   "Noto Sans CJK SC", "SimHei"]
_INSTALLED = {f.name for f in font_manager.fontManager.ttflist}
for _f in _CJK_CANDIDATES:
    if _f in _INSTALLED:
        plt.rcParams["font.sans-serif"] = [_f, "DejaVu Sans"]
        break
plt.rcParams["axes.unicode_minus"] = False

# STACKS is rebound by main() from --stacks; the global is the fallback default
# so callers that import the module still see a sensible pair. Default pairing
# is the paper's two-way baseline: SPIFFE+Keycloak+LSVID vs Vault PKI.
STACKS: tuple[str, ...] = ("spiffe-keycloak", "vault")

# DISPLAY holds the reader-facing label per stack, aligned by index with STACKS.
# It is decoupled from STACKS so the raw/<name>.csv provenance key and the CSV
# `stack` filter key stay stable while charts/summary show the architecture name.
DISPLAY: tuple[str, ...] = ("SPIFFE+Keycloak+LSVID", "Vault PKI")

RESULT_MAP = {
    "rejected": "REJECT",
    "blocked":  "REJECT",
    "accepted": "ACCEPT",
    "n/a":      "NA",
    "unknown":  "NA",
}

# ── Capability-class taxonomy ────────────────────────────────────────────────
# Each case is assigned to exactly one class. Classification is keyed by the
# category, with per-case overrides for the mixed categories (attestation AT2
# forged-cert is a SPIFFE test; identity-blast IB4 forged nested-LSVID is an
# LSVID test).
CLASS_ORDER = ["lsvid", "spiffe", "container-config", "shared-baseline",
               "observability"]

CLASS_DISPLAY = {
    "lsvid":            "LSVID 特有能力 (nested-chain)",
    "spiffe":           "SPIFFE 特有能力 (X.509-SVID/mTLS)",
    "container-config": "容器設定能力 (controlled confound)",
    "shared-baseline":  "共用基線 (both stacks)",
    "observability":    "可觀測性 (informational)",
}

# category → default class (applies to every case in the category unless the
# case_id appears in _CASE_CLASS_OVERRIDE below).
_CATEGORY_CLASS = {
    "lsvid-forgery":         "lsvid",
    "replay":                "lsvid",
    "credential-read":       "spiffe",
    "secret-zero":           "spiffe",
    "credential-lifetime":   "spiffe",
    "attestation":           "spiffe",
    "http-ingress":          "shared-baseline",
    "cross-host":            "shared-baseline",
    "amqp-inject":           "shared-baseline",
    "id-bypass-order":       "shared-baseline",
    "id-bypass-inventory":   "shared-baseline",
    "id-bypass-wallet":      "shared-baseline",
    "lib-hijack":            "container-config",
    "config-audit":          "container-config",
    "port-exposure":         "container-config",
    "observability-diff":    "observability",
    "identity-blast-radius": "shared-baseline",
}

# Per-case exceptions: cases whose class differs from their category default.
_CASE_CLASS_OVERRIDE = {
    "AT2": "spiffe",  # forged-SVID direct call → blocked at mTLS handshake
    "IB4": "lsvid",   # forged nested-LSVID → rejected by per-hop chain check
}


def class_of(case_id: str, category: str) -> str:
    if case_id in _CASE_CLASS_OVERRIDE:
        return _CASE_CLASS_OVERRIDE[case_id]
    return _CATEGORY_CLASS.get(category, "shared-baseline")


# 15-CVE backbone (2024–2025 representative zero-trust CVEs). Each row is an
# analogue realised on the self-built SUT, NOT a product original exploit — see
# scripts/security/CVE-MATRIX.md for the full honesty disclaimer. ★ marks the
# SPIFFE-architecture cases that give RQ4 its measured wins.
CVE_MAPPING = [
    ("http-ingress",
     "CVE#1 Authentication Bypass",
     "CVE-2024-1709 (ScreenConnect)"),
    ("lsvid-forgery",
     "CVE#2 Identity-token Forgery",
     "CVE-2024-45409 (Ruby-SAML)"),
    ("cross-host",
     "CVE#3 Network-segmentation Bypass",
     "CVE-2024-3661 (TunnelVision)"),
    ("amqp-inject",
     "CVE#4 Input-validation Bypass",
     "CVE-2024-38856 (Apache OFBiz)"),
    ("replay",
     "CVE#5 Credential / Session Replay",
     "CVE-2024-21887 / CVE-2024-21893 (Ivanti)"),
    ("lib-hijack",
     "CVE#6 Library Hijack / Supply Chain",
     "CVE-2024-3094 (XZ Utils)"),
    ("id-bypass-{order,inventory,wallet}",
     "CVE#7 Privilege Escalation",
     "CVE-2025-32463 (Sudo chroot)"),
    ("config-audit",
     "CVE#8 Container Isolation / Misconfiguration",
     "CVE-2024-21626 (runc Leaky Vessels)"),
    ("port-exposure",
     "CVE#9 Control-plane / Port Exposure",
     "CVE-2024-57727 / CVE-2024-57728 (SimpleHelp)"),
    ("identity-blast-radius",
     "CVE#10 Identity Management System Risk",
     "CVE-2025-61757 / CVE-2024-7401"),
    ("credential-read ★",
     "CVE#11 Credential Exposure (key material)",
     "CVE-2024-24919 (Check Point)"),
    ("secret-zero ★",
     "CVE#12 Secret Zero / reusable bootstrap",
     "CVE-2024-3400 (PAN-OS)"),
    ("credential-lifetime ★",
     "CVE#13 Stolen-credential validity window",
     "CVE-2024-23897 (Jenkins)"),
    ("attestation ★",
     "CVE#14 Workload-attestation Bypass",
     "CVE-2024-37085 (VMware ESXi)"),
    ("observability-diff",
     "CVE#15 Insufficient Network Visibility",
     "CVE-2024-6387 (regreSSHion)"),
]

# Reader-facing metric name — deliberately NOT "attack coverage".
METRIC_LABEL = "測試案例拒絕比例 (test-case rejection ratio = rejected / scored)"


def normalise(v: str | None) -> str:
    if v is None or (isinstance(v, float) and pd.isna(v)):
        return "NA"
    return RESULT_MAP.get(str(v).lower(), str(v).upper())


def case_id_sort_key(cid: str) -> tuple[str, int]:
    head = "".join(c for c in cid if c.isalpha())
    tail = "".join(c for c in cid if c.isdigit())
    return (head, int(tail) if tail else 0)


def load(path: Path) -> pd.DataFrame:
    if not path.exists():
        print(f"[warn] missing {path}", file=sys.stderr)
        return pd.DataFrame(
            columns=["category", "case_id", "cve_ref", "stack",
                     "result", "latency_ms", "target", "detail"]
        )
    df = pd.read_csv(path)
    df["norm"] = df["result"].map(normalise)
    df["cap_class"] = [class_of(c, cat)
                       for c, cat in zip(df["case_id"], df["category"])]
    return df


def _reject_rate(g: pd.DataFrame) -> dict:
    rej = int((g["norm"] == "REJECT").sum())
    acc = int((g["norm"] == "ACCEPT").sum())
    na  = int((g["norm"] == "NA").sum())
    scored = rej + acc
    rate = rej / scored if scored > 0 else float("nan")
    return dict(reject=rej, accept=acc, na=na,
                total_scored=scored, reject_rate=rate)


def per_group_reject_rate(df: pd.DataFrame, group_col: str) -> pd.DataFrame:
    """Rejection ratio = REJECT / (REJECT + ACCEPT); NA excluded from denom."""
    cols = [group_col, "stack", "reject", "accept", "na",
            "total_scored", "reject_rate"]
    if df.empty:
        return pd.DataFrame(columns=cols)
    rows = []
    for (grp, stk), g in df.groupby([group_col, "stack"]):
        rows.append({group_col: grp, "stack": stk, **_reject_rate(g)})
    return (pd.DataFrame(rows)
            .sort_values([group_col, "stack"]).reset_index(drop=True))


def summary_csv(df: pd.DataFrame, out: Path, group_col: str) -> pd.DataFrame:
    s = per_group_reject_rate(df, group_col)
    out_df = s.copy()
    label_map = dict(zip(STACKS, DISPLAY))
    out_df["stack"] = out_df["stack"].map(lambda k: label_map.get(k, k))
    # n_display makes the small-sample size explicit next to every ratio.
    out_df["n_display"] = out_df.apply(
        lambda r: f"{r['reject']}/{r['total_scored']}", axis=1)
    out_df.to_csv(out, index=False)
    print(f"[ok] summary ({group_col}) → {out}")
    return s


def _bar_pair(ax, x, width, summary, group_col, cats):
    rate = {stk: [] for stk in STACKS}
    ns = {stk: [] for stk in STACKS}
    for c in cats:
        for stk in STACKS:
            row = summary[(summary[group_col] == c) & (summary["stack"] == stk)]
            if not row.empty and not pd.isna(row["reject_rate"].iloc[0]):
                rate[stk].append(float(row["reject_rate"].iloc[0]))
                ns[stk].append(f"{int(row['reject'].iloc[0])}/"
                               f"{int(row['total_scored'].iloc[0])}")
            else:
                rate[stk].append(0.0)
                ns[stk].append("0/0")
    b0 = ax.bar(x - width / 2, rate[STACKS[0]], width,
                label=DISPLAY[0], color="#2563eb")
    b1 = ax.bar(x + width / 2, rate[STACKS[1]], width,
                label=DISPLAY[1], color="#ea580c")
    # annotate every bar with its raw n (reject/scored) so ratios can't be
    # mistaken for statistical coverage.
    for bars, stk in ((b0, STACKS[0]), (b1, STACKS[1])):
        for rect, lbl in zip(bars, ns[stk]):
            ax.annotate(lbl, (rect.get_x() + rect.get_width() / 2,
                              rect.get_height()),
                        ha="center", va="bottom", fontsize=7, color="#334155")


def plot_bars(summary: pd.DataFrame, out: Path, title: str, group_col: str,
              categories: list[str] | None = None) -> None:
    cats = categories or sorted(summary[group_col].unique().tolist())
    if not cats:
        print(f"[note] no groups for {title} — skipping")
        return
    x = np.arange(len(cats))
    width = 0.38
    fig, ax = plt.subplots(figsize=(max(8, len(cats) * 1.4), 5))
    _bar_pair(ax, x, width, summary, group_col, cats)
    ax.set_ylabel(METRIC_LABEL, fontsize=9)
    ax.set_ylim(0, 1.12)
    ax.set_xticks(x)
    ax.set_xticklabels(cats, rotation=25, ha="right", fontsize=8)
    ax.set_title(title)
    ax.legend()
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out, dpi=120)
    plt.close(fig)
    print(f"[ok] {title} → {out}")


def plot_class_bars(class_summary: pd.DataFrame, out: Path) -> None:
    """Headline figure: rejection ratio per CAPABILITY CLASS.

    container-config is drawn but visually flagged as a controlled confound so
    the reader attributes only lsvid/spiffe divergence to the architecture.
    """
    classes = [c for c in CLASS_ORDER
               if c in class_summary["cap_class"].unique().tolist()]
    if not classes:
        print("[note] no capability classes — skipping class bars")
        return
    x = np.arange(len(classes))
    width = 0.38
    fig, ax = plt.subplots(figsize=(max(9, len(classes) * 1.7), 5.2))
    _bar_pair(ax, x, width, class_summary, "cap_class", classes)

    # shade the container-config column to mark it as a controlled variable
    if "container-config" in classes:
        i = classes.index("container-config")
        ax.axvspan(i - 0.5, i + 0.5, color="#94a3b8", alpha=0.14, zorder=0)
        ax.annotate("controlled\n(identical on\nboth stacks)",
                    (i, 1.02), ha="center", va="bottom",
                    fontsize=7, color="#475569")

    ax.set_ylabel(METRIC_LABEL, fontsize=9)
    ax.set_ylim(0, 1.15)
    ax.set_xticks(x)
    ax.set_xticklabels([CLASS_DISPLAY.get(c, c) for c in classes],
                       rotation=15, ha="right", fontsize=8)
    ax.set_title("Rejection ratio by capability class — architecture vs "
                 "controlled container-config")
    ax.legend(loc="upper right")
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out, dpi=120)
    plt.close(fig)
    print(f"[ok] capability-class bars → {out}")


def plot_heatmap(df: pd.DataFrame, out: Path) -> None:
    if df.empty:
        return
    df = df.copy()
    df["sort_key"] = df["case_id"].map(case_id_sort_key)
    df = df.sort_values(["sort_key", "stack"])
    cases = df.drop_duplicates("case_id")["case_id"].tolist()
    matrix = []
    for stk in STACKS:
        row = []
        for c in cases:
            sub = df[(df["case_id"] == c) & (df["stack"] == stk)]
            row.append(sub["norm"].iloc[0] if not sub.empty else "NA")
        matrix.append(row)

    code = {"REJECT": 2, "ACCEPT": 0, "NA": 1}
    grid = np.array([[code.get(c, 1) for c in row] for row in matrix])

    fig, ax = plt.subplots(figsize=(max(8, len(cases) * 0.35), 2.5))
    cmap = matplotlib.colors.ListedColormap(["#fca5a5", "#d4d4d8", "#86efac"])
    ax.imshow(grid, cmap=cmap, vmin=0, vmax=2, aspect="auto")
    ax.set_yticks(range(len(STACKS)))
    ax.set_yticklabels(DISPLAY)
    ax.set_xticks(range(len(cases)))
    ax.set_xticklabels(cases, rotation=90, fontsize=7)
    ax.set_title("case_id × stack — green=REJECT, red=ACCEPT, grey=NA")
    fig.tight_layout()
    fig.savefig(out, dpi=130)
    plt.close(fig)
    print(f"[ok] heatmap → {out}")


def write_cve_mapping(out: Path) -> None:
    lines = ["# CVE → attack-category mapping",
             "",
             "| Attack category | CVE class | Representative CVE(s) |",
             "|---|---|---|"]
    for cat, cls, cves in CVE_MAPPING:
        lines.append(f"| `{cat}` | {cls} | {cves} |")
    out.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"[ok] cve-mapping → {out}")


def main() -> int:
    global STACKS, DISPLAY
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="in_dir", required=True,
                    help="directory containing raw/<stack>.csv files")
    ap.add_argument("--stacks", default="spiffe-keycloak,vault",
                    help="comma-separated stack pair (must match raw/<name>.csv)")
    ap.add_argument("--labels", default="SPIFFE+Keycloak+LSVID,Vault PKI",
                    help="comma-separated display names aligned with --stacks; "
                         "sets the reader-facing label on charts/summary "
                         "without touching the raw provenance key.")
    args = ap.parse_args()
    root = Path(args.in_dir)
    raw = root / "raw"

    stacks = tuple(s.strip() for s in args.stacks.split(",") if s.strip())
    if len(stacks) != 2:
        print("[err] --stacks must list exactly two names", file=sys.stderr)
        return 2
    STACKS = stacks
    labels = tuple(s.strip() for s in args.labels.split(",") if s.strip())
    if labels and len(labels) != 2:
        print("[err] --labels must list exactly two names", file=sys.stderr)
        return 2
    DISPLAY = labels or stacks

    frames = [load(raw / f"{s}.csv") for s in stacks]
    combined = pd.concat(frames, ignore_index=True)
    if combined.empty:
        print("[err] both CSVs empty — nothing to compare", file=sys.stderr)
        return 1

    # ── per-category (existing view, now with sample n + renamed metric) ──
    summary = summary_csv(combined, root / "summary.csv", "category")
    all_cats = sorted(summary["category"].unique().tolist())
    plot_bars(summary, root / "comparison_bars.png",
              "Rejection ratio per attack category", "category")

    pivot = summary.pivot_table(index="category", columns="stack",
                                values="reject_rate", aggfunc="first")
    diverging = []
    for c in all_cats:
        if c not in pivot.index:
            continue
        vals = pivot.loc[c].dropna().tolist()
        if len(vals) == 2 and abs(vals[0] - vals[1]) > 1e-6:
            diverging.append(c)
    if diverging:
        plot_bars(summary, root / "divergence_bars.png",
                  "Rejection-ratio divergence (categories where stacks disagree)",
                  "category", categories=diverging)
    else:
        print("[note] no divergent categories — skipping divergence_bars.png")

    # ── per-capability-class (the headline separation) ──
    class_summary = summary_csv(combined, root / "summary_by_class.csv",
                                "cap_class")
    plot_class_bars(class_summary, root / "capability_class_bars.png")

    plot_heatmap(combined, root / "comparison_heatmap.png")
    write_cve_mapping(root / "cve-mapping.md")

    # ── console recap ──
    print()
    print(f"Metric: {METRIC_LABEL}")
    print("(2–4 cases per category → these are ratios, NOT statistical "
          "coverage percentages.)")
    print()
    print("Per-category rejection ratio:")
    print(pivot.fillna("—").to_string())
    print()
    class_pivot = class_summary.pivot_table(
        index="cap_class", columns="stack", values="reject_rate",
        aggfunc="first").reindex(CLASS_ORDER).dropna(how="all")
    print("Per-capability-class rejection ratio "
          "(container-config is CONTROLLED — identical on both stacks):")
    print(class_pivot.fillna("—").to_string())
    return 0


if __name__ == "__main__":
    sys.exit(main())
