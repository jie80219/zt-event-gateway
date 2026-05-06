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
        "Noto Sans CJK TC", "Noto Sans CJK SC", "Source Han Sans TC",
        "Microsoft JhengHei", "SimHei",
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
    fig, ax = plt.subplots(figsize=(10, 6))
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
        boxes, positions=positions, widths=width * 0.9,
        patch_artist=True, showfliers=False,
    )
    for patch, lab in zip(bp["boxes"], labels_x):
        r = lab.split("\n")[1]
        patch.set_facecolor(ROUND_COLORS[r])
        patch.set_alpha(0.55)
    ax.set_xticks([i * 1.0 for i in range(len(scales))])
    ax.set_xticklabels([str(n) for n in scales])
    ax.set_xlabel("請求數")
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    legend_handles = [
        plt.Rectangle((0, 0), 1, 1, fc=ROUND_COLORS[r], alpha=0.55, label=r)
        for r in rounds
    ]
    ax.legend(handles=legend_handles, loc="upper left")
    ax.grid(axis="y", alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_png, dpi=120)
    plt.close(fig)


def plot_bar_incomplete(df: pd.DataFrame, out_png: Path) -> None:
    """Bar chart of incomplete rate per (scale, round)."""
    fig, ax = plt.subplots(figsize=(9, 6))
    pivot = df.pivot(index="scale", columns="round", values="incomplete_rate_pct")
    scales = sorted(pivot.index)
    x = range(len(scales))
    width = 0.35
    for i, r in enumerate(ROUNDS):
        if r not in pivot.columns:
            continue
        ys = pivot[r].reindex(scales).values
        ax.bar([xi + (i - 0.5) * width for xi in x], ys,
               width=width, label=r, color=ROUND_COLORS[r], alpha=0.8)
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
    with pd.ExcelWriter(summary_xlsx, engine="openpyxl") as xw:
        # Gateway latency stats
        gw_rows = []
        for (n, r), g in gw_long.groupby(["scale", "round"]):
            d = stat_dict(g["gw_proc_ms"]); d["scale"] = n; d["round"] = r
            gw_rows.append(d)
        pd.DataFrame(gw_rows).to_excel(xw, sheet_name="Gateway接收請求時間", index=False)

        oc_rows = []
        for (n, r), g in oc.groupby(["scale", "round"]):
            d = stat_dict(g["order_completion_ms"]); d["scale"] = n; d["round"] = r
            oc_rows.append(d)
        pd.DataFrame(oc_rows).to_excel(xw, sheet_name="訂單完成時間", index=False)

        rate_df.to_excel(xw, sheet_name="未完成交易率", index=False)

        if not mtls_df.empty:
            mtls_rows = []
            for (n, r), g in mtls_df.groupby(["scale", "round"]):
                d = stat_dict(g["handshake_ms"]); d["scale"] = n; d["round"] = r
                mtls_rows.append(d)
            pd.DataFrame(mtls_rows).to_excel(xw, sheet_name="mTLS花費時間", index=False)

    print(f"[analyze] outputs written to {indir}")
    print(f"  - {gw_xlsx.name}, {gw_png.name}")
    print(f"  - {oc_xlsx.name}, {oc_png.name}")
    print(f"  - {rate_xlsx.name}, {rate_png.name}")
    if not mtls_df.empty:
        print(f"  - {mtls_xlsx.name}, {mtls_png.name}")
    print(f"  - {summary_xlsx.name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
