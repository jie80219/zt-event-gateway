#!/usr/bin/env python3
"""
Dual-mode (SPIFFE+Keycloak) experiment analyzer.

Reads:
    <in>/raw/load_<round>_<N>.csv
    <in>/raw/worker_<round>_<N>.log
    <in>/raw/mtls_<round>_<N>.err

For each (round ∈ {warm, cold}, N ∈ {5000, 10000, 20000}):
    1. Gateway接收請求時間 = gw_proc_ms from [perf-request-in]
       (gateway received → publish-to-RabbitMQ time)
    2. 訂單完成時間 = (saga_complete_ts − gateway_publish_ts)
       (publish-to-queue → OrderSagaCompletedEvent), per user 2.b
    3. 未完成率 = (總完成交易次數 − 成功完成交易次數) / 總交易次數 × 100
       per user 3.C: 「完成」= step1 fired (saga reached worker), 「成功完成」=
       saga reached SagaCompletedEvent. So this counts every saga that started
       but didn't finish successfully, divided by total dispatched.
    4. mTLS花費時間 = handshake_ms from [perf-mtls] (independent probe)

Outputs:
    Gateway接收請求時間.{xlsx,png}
    訂單完成時間.{xlsx,png}
    未完成交易率.{xlsx,png}
    mTLS花費時間.{xlsx,png}
    summary.xlsx
    README.md  (env description)
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

import matplotlib
matplotlib.use("Agg")
import matplotlib.font_manager as fm
import matplotlib.pyplot as plt
import pandas as pd

ROUNDS = ("warm", "cold")
ROUND_COLORS = {"warm": "#d62728", "cold": "#1f77b4"}


def setup_cjk_font() -> None:
    candidates = [
        "Arial Unicode MS", "Heiti TC", "PingFang TC", "PingFang SC",
        "Noto Sans CJK TC", "Noto Sans CJK SC", "Noto Sans CJK JP",
        "Source Han Sans TC", "Microsoft JhengHei", "SimHei",
    ]
    available = {f.name for f in fm.fontManager.ttflist}
    chosen = next((c for c in candidates if c in available), None)
    if chosen:
        plt.rcParams["font.sans-serif"] = [chosen, "DejaVu Sans"]
    plt.rcParams["axes.unicode_minus"] = False


# ── Log regexes ────────────────────────────────────────────────────────
RE_REQ_IN = re.compile(
    r"\[perf-request-in\]\s+ts_in=(?P<ts_in>[\d.]+)\s+ts_out=(?P<ts_out>[\d.]+)"
    r"\s+gw_proc_ms=(?P<gw>[\d.]+)\s+traceId=(?P<trace>\S+)"
)
RE_STEP1 = re.compile(
    r"\[perf-saga-step1\]\s+ts=(?P<ts>[\d.]+)\s+orderId=(?P<oid>\S+)"
    r"\s+traceId=(?P<trace>\S*)"
)
RE_COMPLETE = re.compile(
    r"\[perf-saga-complete\]\s+ts=(?P<ts>[\d.]+)\s+orderId=(?P<oid>\S+)"
)
RE_MTLS = re.compile(
    r"\[perf-mtls\]\s+handshake_ms=(?P<hs>[\d.-]+)\s+connect_ms=(?P<conn>[\d.-]+)"
    r"\s+total_ms=(?P<tot>[\d.]+)\s+url=(?P<url>\S+)"
)


def parse_worker_log(path: Path):
    publish_ts = {}    # trace → ts_out (gateway publish)
    gw_ms = {}         # trace → gw_proc_ms
    step1_oid = {}     # trace → orderId
    complete_ts = {}   # orderId → ts
    if not path.exists():
        return publish_ts, gw_ms, step1_oid, complete_ts
    with path.open("r", errors="replace") as f:
        for line in f:
            m = RE_REQ_IN.search(line)
            if m:
                publish_ts[m["trace"]] = float(m["ts_out"])
                gw_ms[m["trace"]] = float(m["gw"])
                continue
            m = RE_STEP1.search(line)
            if m:
                step1_oid[m["trace"]] = m["oid"]
                continue
            m = RE_COMPLETE.search(line)
            if m:
                complete_ts[m["oid"]] = float(m["ts"])
    return publish_ts, gw_ms, step1_oid, complete_ts


def parse_mtls_log(path: Path):
    samples = []
    if not path.exists():
        return samples
    with path.open("r", errors="replace") as f:
        for line in f:
            m = RE_MTLS.search(line)
            if m:
                hs = float(m["hs"])
                if hs > 0:  # filter out failed handshakes
                    samples.append({
                        "handshake_ms": hs,
                        "connect_ms": float(m["conn"]),
                        "total_ms": float(m["tot"]),
                    })
    return samples


# ── Per (round, scale) assembly ────────────────────────────────────────
def assemble_one(raw: Path, round_label: str, n: int):
    csv_path = raw / f"load_{round_label}_{n}.csv"
    wlog = raw / f"worker_{round_label}_{n}.log"
    mlog = raw / f"mtls_{round_label}_{n}.err"

    if not csv_path.exists():
        sys.stderr.write(f"[analyze] WARN: missing {csv_path}\n")
        return None, None

    df = pd.read_csv(csv_path)
    publish_ts, gw_ms, step1_oid, complete_ts = parse_worker_log(wlog)

    def saga_complete_ms(trace_id: str) -> float:
        oid = step1_oid.get(trace_id)
        if oid is None:
            return float("nan")
        c = complete_ts.get(oid)
        if c is None:
            return float("nan")
        p = publish_ts.get(trace_id)
        if p is None:
            return float("nan")
        return (c - p) * 1000.0

    df["round"] = round_label
    df["scale"] = n
    df["gw_proc_ms"] = df["trace_id"].map(gw_ms)
    df["order_completion_ms"] = df["trace_id"].apply(saga_complete_ms)
    df["step1_fired"] = df["trace_id"].isin(step1_oid)
    df["completed"] = df["trace_id"].map(
        lambda t: step1_oid.get(t) in complete_ts
    )

    mtls_samples = parse_mtls_log(mlog)
    mtls_df = pd.DataFrame(mtls_samples)
    if not mtls_df.empty:
        mtls_df["round"] = round_label
        mtls_df["scale"] = n
    return df, mtls_df


# ── Stats ──────────────────────────────────────────────────────────────
def quant(s: pd.Series, q: float) -> float:
    s = pd.to_numeric(s, errors="coerce").dropna()
    return float(s.quantile(q)) if len(s) else float("nan")


def stat_dict(s: pd.Series) -> dict:
    s = pd.to_numeric(s, errors="coerce").dropna()
    if not len(s):
        return {"count": 0, "mean": float("nan"), "p50": float("nan"),
                "p90": float("nan"), "p95": float("nan"), "p99": float("nan"),
                "max": float("nan")}
    return {
        "count": int(len(s)),
        "mean": float(s.mean()),
        "p50":  quant(s, 0.50),
        "p90":  quant(s, 0.90),
        "p95":  quant(s, 0.95),
        "p99":  quant(s, 0.99),
        "max":  float(s.max()),
    }


# ── Plot helpers ───────────────────────────────────────────────────────
def plot_box_by_scale_round(df: pd.DataFrame, value_col: str, title: str,
                            ylabel: str, out_png: Path) -> None:
    import numpy as np
    fig, ax = plt.subplots(figsize=(14, 9))
    scales = sorted(df["scale"].unique())
    rounds = ROUNDS
    width = 0.35
    positions = []
    boxes = []
    labels_x = []
    for i, n in enumerate(scales):
        for j, r in enumerate(rounds):
            sub = df[(df["scale"] == n) & (df["round"] == r)][value_col]
            sub = pd.to_numeric(sub, errors="coerce").dropna()
            pos = i * 1.0 + (j - 0.5) * width
            positions.append(pos)
            boxes.append(sub.values)
            labels_x.append(f"{n}\n{r}")
    bp = ax.boxplot(
        boxes, positions=positions, widths=width * 0.95,
        patch_artist=True, showfliers=True, showmeans=True,
        boxprops=dict(linewidth=2.0, edgecolor="#222"),
        whiskerprops=dict(linewidth=1.8, color="#222"),
        capprops=dict(linewidth=1.8, color="#222"),
        medianprops=dict(linewidth=3.0, color="orange"),
        flierprops=dict(marker="o", markersize=3.5,
                        markerfacecolor="dimgray",
                        markeredgecolor="dimgray", alpha=0.45),
        meanprops=dict(marker="^", markerfacecolor="lime",
                       markeredgecolor="darkgreen", markersize=11),
    )
    for patch, lab in zip(bp["boxes"], labels_x):
        r = lab.split("\n")[1]
        patch.set_facecolor(ROUND_COLORS[r])
        patch.set_alpha(0.75)

    # Annotate max / mean / median / min above each box
    for pos, sub in zip(positions, boxes):
        if len(sub) == 0:
            continue
        mx = float(np.max(sub)); mn = float(np.min(sub))
        med = float(np.median(sub)); mean = float(np.mean(sub))
        ax.annotate(
            f"max={mx:.2f}\nμ={mean:.2f}\nM={med:.2f}\nmin={mn:.2f}",
            xy=(pos, mx), xytext=(0, 6), textcoords="offset points",
            ha="center", va="bottom", fontsize=7,
            bbox=dict(boxstyle="round,pad=0.25", fc="white",
                      ec="gray", alpha=0.85),
        )

    ax.set_xticks([i * 1.0 for i in range(len(scales))])
    ax.set_xticklabels([str(n) for n in scales])
    ax.set_xlabel("請求數")
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    legend_handles = [
        plt.Rectangle((0, 0), 1, 1, fc=ROUND_COLORS[r], alpha=0.55, label=r)
        for r in rounds
    ]
    legend_handles.append(
        plt.Line2D([0], [0], marker="^", color="w", markerfacecolor="lime",
                   markeredgecolor="darkgreen", markersize=8, label="mean (μ)")
    )
    ax.legend(handles=legend_handles, loc="upper left")
    ax.grid(axis="y", alpha=0.3)
    # Auto-pick log scale when outliers dwarf the main distribution
    all_vals = np.concatenate([s for s in boxes if len(s)]) if boxes else np.array([])
    use_log = (
        all_vals.size > 0
        and all_vals.min() > 0
        and float(all_vals.max()) / max(float(np.median(all_vals)), 1e-9) > 10
    )
    if use_log:
        ax.set_yscale("log")
    else:
        y_max = float(all_vals.max()) if all_vals.size else 1.0
        y_min = float(all_vals.min()) if all_vals.size else 0.0
        ax.set_ylim(y_min - (y_max - y_min) * 0.05,
                    y_max + (y_max - y_min) * 0.30)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def plot_box_single_scale(df: pd.DataFrame, value_col: str, scale: int,
                          title: str, ylabel: str, out_png: Path) -> None:
    """Box plot for one scale, warm vs cold side by side."""
    fig, ax = plt.subplots(figsize=(7, 6))
    sub_df = df[df["scale"] == scale]
    boxes = []
    positions = []
    for j, r in enumerate(ROUNDS):
        s = sub_df[sub_df["round"] == r][value_col]
        s = pd.to_numeric(s, errors="coerce").dropna()
        boxes.append(s.values)
        positions.append(j)
    bp = ax.boxplot(boxes, positions=positions, widths=0.55,
                    patch_artist=True, showfliers=True)
    for patch, r in zip(bp["boxes"], ROUNDS):
        patch.set_facecolor(ROUND_COLORS[r])
        patch.set_alpha(0.6)
    ax.set_xticks(positions)
    ax.set_xticklabels([str(r) for r in ROUNDS])
    ax.set_xlabel("round")
    ax.set_ylabel(ylabel)
    ax.set_title(f"{title} — 請求數={scale}")
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def plot_bar_single_scale(rate_df: pd.DataFrame, scale: int, out_png: Path) -> None:
    """Bar chart of incomplete rate for one scale, warm vs cold."""
    fig, ax = plt.subplots(figsize=(6, 6))
    sub = rate_df[rate_df["scale"] == scale]
    rs, ys, colors = [], [], []
    for r in ROUNDS:
        row = sub[sub["round"] == r]
        if row.empty:
            continue
        rs.append(r)
        ys.append(float(row["incomplete_rate_pct"].iloc[0]))
        colors.append(ROUND_COLORS[r])
    bars = ax.bar(rs, ys, color=colors, alpha=0.8, width=0.55)
    for bar, y in zip(bars, ys):
        ax.text(bar.get_x() + bar.get_width() / 2, y,
                f"{y:.2f}%", ha="center", va="bottom", fontsize=10)
    ax.set_xlabel("round")
    ax.set_ylabel("未完成率 (%)")
    ax.set_title(f"未完成交易率 — 請求數={scale}")
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def write_per_scale_box(long_df: pd.DataFrame, value_col: str, scales: list,
                        indir: Path, name_prefix: str, title: str,
                        ylabel: str) -> None:
    """For each scale: produce {prefix}_{scale}.{xlsx,png}."""
    for n in scales:
        sub = long_df[long_df["scale"] == n]
        if sub.empty:
            continue
        xlsx = indir / f"{name_prefix}_{n}.xlsx"
        png = indir / f"{name_prefix}_{n}.png"
        with pd.ExcelWriter(xlsx, engine="openpyxl") as xw:
            sub.to_excel(xw, sheet_name="raw", index=False)
            rows = []
            for r, g in sub.groupby("round"):
                d = stat_dict(g[value_col]); d["round"] = r; d["scale"] = n
                rows.append(d)
            pd.DataFrame(rows).to_excel(xw, sheet_name="summary", index=False)
        plot_box_single_scale(sub, value_col, n, title, ylabel, png)


def plot_bar_incomplete(df: pd.DataFrame, out_png: Path) -> None:
    """Bar chart of incomplete rate per (scale, round) with value labels."""
    fig, ax = plt.subplots(figsize=(11, 6.5))
    pivot = df.pivot(index="scale", columns="round", values="incomplete_rate_pct")
    scales = sorted(pivot.index)
    x = range(len(scales))
    width = 0.35
    for i, r in enumerate(ROUNDS):
        if r not in pivot.columns:
            continue
        ys = pivot[r].reindex(scales).values
        bars = ax.bar([xi + (i - 0.5) * width for xi in x], ys,
                      width=width, label=r, color=ROUND_COLORS[r], alpha=0.8)
        for bar, y in zip(bars, ys):
            if pd.isna(y):
                continue
            ax.text(bar.get_x() + bar.get_width() / 2, y,
                    f"{y:.2f}%", ha="center", va="bottom", fontsize=10)
    ax.set_xticks(list(x))
    ax.set_xticklabels([str(s) for s in scales])
    ax.set_xlabel("請求數")
    ax.set_ylabel("未完成率 (%)")
    ax.set_title("未完成交易率 per (請求數, round)")
    ax.legend()
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


# ── Metadata helpers ───────────────────────────────────────────────────
def load_metadata(indir: Path) -> dict | None:
    p = indir / "metadata.json"
    if not p.exists():
        return None
    try:
        return json.loads(p.read_text(encoding="utf-8"))
    except Exception as e:
        sys.stderr.write(f"[analyze] WARN: failed to parse metadata.json: {e}\n")
        return None


def metadata_to_rows(meta: dict) -> list[dict]:
    rows: list[dict] = []
    for k, v in meta.items():
        if isinstance(v, (dict, list)):
            v = json.dumps(v, ensure_ascii=False)
        rows.append({"key": k, "value": v})
    return rows


def write_readme(indir: Path, meta: dict | None,
                 gw_rows: list[dict], oc_rows: list[dict],
                 rate_df: pd.DataFrame, mtls_rows: list[dict]) -> None:
    lines: list[str] = []
    lines.append("# Experimental Run Report")
    lines.append("")
    if meta:
        lines.append("## Metadata")
        lines.append("")
        lines.append(f"- **stamp**: {meta.get('stamp')}")
        lines.append(f"- **branch**: `{meta.get('branch')}`")
        lines.append(f"- **gateway_workers** (`$worker->count`): "
                     f"{meta.get('gateway_workers')}")
        lines.append(f"- **worker_consumer_processes** (`numprocs`): "
                     f"{meta.get('worker_consumer_processes')}")
        lines.append(f"- **amqp_prefetch_count**: "
                     f"{meta.get('amqp_prefetch_count')}")
        lines.append(f"- **profile flags**: "
                     f"SPIFFE={meta.get('spiffe_enabled')}, "
                     f"LSVID_REQUIRED={meta.get('lsvid_required')}, "
                     f"MTLS={meta.get('mtls_enabled')}, "
                     f"KEYCLOAK={meta.get('keycloak_enabled')}")
        lines.append(f"- **scales**: {meta.get('scales')}")
        lines.append(f"- **rounds**: {meta.get('rounds')}")
        commits = meta.get("commit_sha") or {}
        if commits:
            lines.append("- **commit_sha**:")
            for h, sha in commits.items():
                lines.append(f"    - `{h}`: `{sha}`")
        lines.append(f"- **started_at**: {meta.get('started_at')}")
        lines.append("")
    else:
        lines.append("> metadata.json not found — run via "
                     "`scripts/experiments/run-experimental-full.sh` "
                     "to capture it next time.")
        lines.append("")

    lines.append("## Summary statistics")
    lines.append("")

    def _table(title: str, rows: list[dict], value_keys: list[str]) -> None:
        if not rows:
            return
        lines.append(f"### {title}")
        lines.append("")
        header = ["scale", "round"] + value_keys
        lines.append("| " + " | ".join(header) + " |")
        lines.append("|" + "|".join(["---"] * len(header)) + "|")
        for r in rows:
            lines.append("| " + " | ".join(
                str(r.get(k, "")) if not isinstance(r.get(k), float)
                else f"{r[k]:.3f}" for k in header
            ) + " |")
        lines.append("")

    _table("Gateway 接收請求時間 (ms)", gw_rows,
           ["count", "mean", "p50", "p95", "p99", "max"])
    _table("訂單完成時間 (ms)", oc_rows,
           ["count", "mean", "p50", "p95", "p99", "max"])

    if not rate_df.empty:
        lines.append("### 未完成交易率")
        lines.append("")
        lines.append("| scale | round | total | step1_fired | completed | "
                     "incomplete | rate (%) |")
        lines.append("|---|---|---|---|---|---|---|")
        for _, r in rate_df.iterrows():
            lines.append(
                f"| {r['scale']} | {r['round']} | {r['total']} | "
                f"{r['step1_fired_(完成)']} | {r['saga_completed_(成功)']} | "
                f"{r['incomplete']} | {r['incomplete_rate_pct']:.4f} |"
            )
        lines.append("")

    _table("mTLS handshake (ms)", mtls_rows,
           ["count", "mean", "p50", "p95", "p99", "max"])

    lines.append("## Files")
    lines.append("")
    for fname in ("Gateway接收請求時間.xlsx", "Gateway接收請求時間.png",
                  "訂單完成時間.xlsx", "訂單完成時間.png",
                  "未完成交易率.xlsx", "未完成交易率.png",
                  "mTLS花費時間.xlsx", "mTLS花費時間.png",
                  "summary.xlsx", "metadata.json"):
        if (indir / fname).exists():
            lines.append(f"- `{fname}`")
    lines.append("")

    (indir / "README.md").write_text("\n".join(lines), encoding="utf-8")


# ── Main ───────────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="indir", required=True)
    ap.add_argument("--scales", default="5000,10000,20000")
    args = ap.parse_args()
    setup_cjk_font()

    indir = Path(args.indir)
    raw = indir / "raw"
    scales = [int(s) for s in args.scales.split(",")]

    metadata = load_metadata(indir)

    all_req = []
    all_mtls = []
    for r in ROUNDS:
        for n in scales:
            df, mtls = assemble_one(raw, r, n)
            if df is not None:
                all_req.append(df)
            if mtls is not None and not mtls.empty:
                all_mtls.append(mtls)

    if not all_req:
        sys.stderr.write("[analyze] no data found\n")
        return 1

    req_df = pd.concat(all_req, ignore_index=True)
    mtls_df = pd.concat(all_mtls, ignore_index=True) if all_mtls else pd.DataFrame()

    # ── 1. Gateway 接收請求時間 ───────────────────────────────────────
    gw_xlsx = indir / "Gateway接收請求時間.xlsx"
    gw_png = indir / "Gateway接收請求時間.png"
    gw_long = req_df[["scale", "round", "trace_id", "gw_proc_ms"]].copy()
    gw_long = gw_long.dropna(subset=["gw_proc_ms"])
    with pd.ExcelWriter(gw_xlsx, engine="openpyxl") as xw:
        gw_long.to_excel(xw, sheet_name="raw", index=False)
        rows = []
        for (n, r), g in gw_long.groupby(["scale", "round"]):
            d = stat_dict(g["gw_proc_ms"])
            d["scale"] = n; d["round"] = r
            rows.append(d)
        pd.DataFrame(rows).to_excel(xw, sheet_name="summary", index=False)
    plot_box_by_scale_round(
        gw_long, "gw_proc_ms",
        title="Gateway 接收請求時間 (gateway 接收 → publish to order_queue)",
        ylabel="Gateway 處理時間 (ms)",
        out_png=gw_png,
    )

    # ── 2. 訂單完成時間 (publish → SagaCompleted) ─────────────────────
    oc_xlsx = indir / "訂單完成時間.xlsx"
    oc_png = indir / "訂單完成時間.png"
    oc = req_df[["scale", "round", "trace_id", "order_completion_ms"]].copy()
    oc = oc.dropna(subset=["order_completion_ms"])
    with pd.ExcelWriter(oc_xlsx, engine="openpyxl") as xw:
        oc.to_excel(xw, sheet_name="raw", index=False)
        rows = []
        for (n, r), g in oc.groupby(["scale", "round"]):
            d = stat_dict(g["order_completion_ms"])
            d["scale"] = n; d["round"] = r
            rows.append(d)
        pd.DataFrame(rows).to_excel(xw, sheet_name="summary", index=False)
    plot_box_by_scale_round(
        oc, "order_completion_ms",
        title="訂單完成時間 (gateway publish → OrderSagaCompletedEvent)",
        ylabel="完成時間 (ms)",
        out_png=oc_png,
    )

    # ── 3. 未完成交易率 ─────────────────────────────────────────────────
    # 3.C 公式：(總完成交易次數 - 成功完成交易次數) / 總交易次數 × 100
    # 「完成」= step1 觸發過（saga reached worker pipeline）
    # 「成功完成」= saga 跑到 OrderSagaCompletedEvent
    rate_xlsx = indir / "未完成交易率.xlsx"
    rate_png  = indir / "未完成交易率.png"
    rate_rows = []
    for (n, r), g in req_df.groupby(["scale", "round"]):
        total = len(g)
        step1_fired = int(g["step1_fired"].sum())
        completed   = int(g["completed"].sum())
        incomplete  = step1_fired - completed
        rate = (incomplete / total * 100.0) if total else 0.0
        rate_rows.append({
            "scale": n,
            "round": r,
            "total": total,
            "step1_fired_(完成)": step1_fired,
            "saga_completed_(成功)": completed,
            "incomplete": incomplete,
            "incomplete_rate_pct": round(rate, 4),
        })
    rate_df = pd.DataFrame(rate_rows)
    with pd.ExcelWriter(rate_xlsx, engine="openpyxl") as xw:
        rate_df.to_excel(xw, sheet_name="summary", index=False)
    plot_bar_incomplete(rate_df, rate_png)

    # ── 4. mTLS 花費時間 ───────────────────────────────────────────────
    mtls_xlsx = indir / "mTLS花費時間.xlsx"
    mtls_png  = indir / "mTLS花費時間.png"
    if not mtls_df.empty:
        with pd.ExcelWriter(mtls_xlsx, engine="openpyxl") as xw:
            mtls_df.to_excel(xw, sheet_name="raw", index=False)
            rows = []
            for (n, r), g in mtls_df.groupby(["scale", "round"]):
                d = stat_dict(g["handshake_ms"])
                d["scale"] = n; d["round"] = r
                rows.append(d)
            pd.DataFrame(rows).to_excel(xw, sheet_name="summary", index=False)
        plot_box_by_scale_round(
            mtls_df, "handshake_ms",
            title="mTLS handshake 花費時間 (probe inside zt-php-worker)",
            ylabel="handshake_ms",
            out_png=mtls_png,
        )
    else:
        sys.stderr.write("[analyze] WARN: no mTLS samples found\n")

    # ── summary.xlsx ───────────────────────────────────────────────────
    summary_xlsx = indir / "summary.xlsx"
    gw_rows: list[dict] = []
    oc_rows: list[dict] = []
    mtls_rows: list[dict] = []
    with pd.ExcelWriter(summary_xlsx, engine="openpyxl") as xw:
        # Metadata sheet (固定條件、profile flag、commit sha 等)
        if metadata:
            pd.DataFrame(metadata_to_rows(metadata)).to_excel(
                xw, sheet_name="metadata", index=False)

        # Gateway latency stats
        for (n, r), g in gw_long.groupby(["scale", "round"]):
            d = stat_dict(g["gw_proc_ms"]); d["scale"] = n; d["round"] = r
            gw_rows.append(d)
        pd.DataFrame(gw_rows).to_excel(xw, sheet_name="Gateway接收請求時間", index=False)

        for (n, r), g in oc.groupby(["scale", "round"]):
            d = stat_dict(g["order_completion_ms"]); d["scale"] = n; d["round"] = r
            oc_rows.append(d)
        pd.DataFrame(oc_rows).to_excel(xw, sheet_name="訂單完成時間", index=False)

        rate_df.to_excel(xw, sheet_name="未完成交易率", index=False)

        if not mtls_df.empty:
            for (n, r), g in mtls_df.groupby(["scale", "round"]):
                d = stat_dict(g["handshake_ms"]); d["scale"] = n; d["round"] = r
                mtls_rows.append(d)
            pd.DataFrame(mtls_rows).to_excel(xw, sheet_name="mTLS花費時間", index=False)

    write_readme(indir, metadata, gw_rows, oc_rows, rate_df, mtls_rows)

    print(f"[analyze] outputs written to {indir}")
    print(f"  - {gw_xlsx.name}, {gw_png.name}")
    print(f"  - {oc_xlsx.name}, {oc_png.name}")
    print(f"  - {rate_xlsx.name}, {rate_png.name}")
    if not mtls_df.empty:
        print(f"  - {mtls_xlsx.name}, {mtls_png.name}")
    print(f"  - {summary_xlsx.name}, README.md")
    return 0


if __name__ == "__main__":
    sys.exit(main())
