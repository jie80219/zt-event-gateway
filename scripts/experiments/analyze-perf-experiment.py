#!/usr/bin/env python3
"""
Analyze the per-scale CSV / log artifacts produced by run-perf-experiment.sh
and emit four xlsx files + four png plots + summary.xlsx.

Inputs (per scale N):
    <in>/load_<N>.csv       columns: seq, trace_id, t_req_ms, t_resp_ms,
                                     http_code, gateway_latency_ms
    <in>/worker_<N>.log     filtered [perf-*] lines:
        [perf-request-in] ts_in=… ts_out=… gw_proc_ms=… traceId=…
        [perf-saga-step1] ts=…    orderId=… traceId=…
        [perf-saga-complete] ts=… orderId=…
        [perf-mtls]    handshake_ms=… connect_ms=… total_ms=… url=…

Outputs (in <out>):
    Gateway接收請求時間.xlsx   /  .png    long-form gateway_latency_ms by scale
    訂單完成時間.xlsx          /  .png    long-form order_completion_ms by scale
    未完成交易率.xlsx          /  .png    summary table per scale
    mTLS花費時間.xlsx          /  .png    long-form handshake_ms by scale
    summary.xlsx              all four metrics' p50/p90/p95/p99/mean by scale
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path
from typing import Dict, List, Tuple

import pandas as pd
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import matplotlib.font_manager as fm


# ── CJK font fallback chain ────────────────────────────────────────────────
def _setup_cjk_font() -> None:
    candidates = [
        "Arial Unicode MS",
        "Heiti TC",
        "PingFang TC",
        "PingFang SC",
        "Noto Sans CJK TC",
        "Noto Sans CJK SC",
        "Source Han Sans TC",
        "Microsoft JhengHei",
        "SimHei",
    ]
    available = {f.name for f in fm.fontManager.ttflist}
    chosen = next((c for c in candidates if c in available), None)
    if chosen:
        plt.rcParams["font.sans-serif"] = [chosen, "DejaVu Sans"]
    plt.rcParams["axes.unicode_minus"] = False


# ── Log parsing ────────────────────────────────────────────────────────────
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
RE_ROLLED_BACK = re.compile(
    r"\[perf-saga-rolled-back\]\s+ts=(?P<ts>[\d.]+)\s+orderId=(?P<oid>\S+)"
    r"\s+outcome=(?P<outcome>\S+)"
)
RE_MTLS = re.compile(
    r"\[perf-mtls\]\s+handshake_ms=(?P<hs>[\d.-]+)\s+connect_ms=(?P<conn>[\d.-]+)"
    r"\s+total_ms=(?P<tot>[\d.]+)\s+url=(?P<url>\S+)"
)


def parse_log(path: Path) -> Dict[str, object]:
    request_in: Dict[str, float] = {}     # traceId -> ts_in (epoch sec)
    gw_proc_ms: Dict[str, float] = {}     # traceId -> in-gateway processing ms
    step1_trace_to_order: Dict[str, str] = {}
    complete_order_to_ts: Dict[str, float] = {}
    rolled_back: Dict[str, Dict[str, object]] = {}  # orderId -> {ts, outcome}
    mtls_samples: List[Dict[str, float]] = []

    if not path.exists():
        return {
            "request_in": request_in,
            "gw_proc_ms": gw_proc_ms,
            "step1": step1_trace_to_order,
            "complete": complete_order_to_ts,
            "rolled_back": rolled_back,
            "mtls": mtls_samples,
        }

    with path.open("r", errors="replace") as f:
        for line in f:
            m = RE_REQ_IN.search(line)
            if m:
                request_in[m["trace"]] = float(m["ts_in"])
                gw_proc_ms[m["trace"]] = float(m["gw"])
                continue
            m = RE_STEP1.search(line)
            if m:
                step1_trace_to_order[m["trace"]] = m["oid"]
                continue
            m = RE_COMPLETE.search(line)
            if m:
                complete_order_to_ts[m["oid"]] = float(m["ts"])
                continue
            m = RE_ROLLED_BACK.search(line)
            if m:
                rolled_back[m["oid"]] = {
                    "ts": float(m["ts"]),
                    "outcome": m["outcome"],
                }
                continue
            m = RE_MTLS.search(line)
            if m:
                mtls_samples.append({
                    "handshake_ms": float(m["hs"]),
                    "connect_ms": float(m["conn"]),
                    "total_ms": float(m["tot"]),
                    "url": m["url"],
                })
                continue

    return {
        "request_in": request_in,
        "gw_proc_ms": gw_proc_ms,
        "step1": step1_trace_to_order,
        "complete": complete_order_to_ts,
        "rolled_back": rolled_back,
        "mtls": mtls_samples,
    }


# ── Per-scale assembly ─────────────────────────────────────────────────────
def assemble_scale(in_dir: Path, n: int) -> Tuple[pd.DataFrame, pd.DataFrame]:
    """Returns (per_request_df, mtls_df) for this scale."""
    csv_path = in_dir / f"load_{n}.csv"
    log_path = in_dir / f"worker_{n}.log"

    if not csv_path.exists():
        sys.stderr.write(f"[analyze] WARN: missing {csv_path}\n")
        return pd.DataFrame(), pd.DataFrame()

    df = pd.read_csv(csv_path)
    parsed = parse_log(log_path)

    step1 = parsed["step1"]
    complete = parsed["complete"]
    rolled_back = parsed["rolled_back"]
    request_in_log = parsed["request_in"]

    def saga_completion_ms(row: pd.Series) -> float:
        trace = row["trace_id"]
        oid = step1.get(trace)
        if oid is None:
            return float("nan")
        ct = complete.get(oid)
        if ct is None:
            return float("nan")
        ts_in_sec = request_in_log.get(trace)
        if ts_in_sec is not None:
            start_sec = ts_in_sec
        else:
            start_sec = row["t_req_ms"] / 1000.0
        return (ct - start_sec) * 1000.0

    def lookup_status(trace: str) -> str:
        oid = step1.get(trace)
        if oid is None:
            return "queued"  # never picked up by worker
        if oid in complete:
            return "success"
        if oid in rolled_back:
            return "rolled_back"
        return "in_flight"  # step1 fired but no terminal event yet

    df["scale"] = n
    df["order_completion_ms"] = df.apply(saga_completion_ms, axis=1)
    df["status"] = df["trace_id"].apply(lookup_status)
    df["completed"] = df["status"] == "success"
    df["rolled_back"] = df["status"] == "rolled_back"
    df["gw_proc_ms"] = df["trace_id"].map(parsed["gw_proc_ms"])

    mtls_df = pd.DataFrame(parsed["mtls"])
    if not mtls_df.empty:
        mtls_df["scale"] = n
        mtls_df = mtls_df[mtls_df["handshake_ms"] > 0].reset_index(drop=True)

    return df, mtls_df


# ── Stats helpers ──────────────────────────────────────────────────────────
def stat_row(series: pd.Series) -> Dict[str, float]:
    s = series.dropna()
    if s.empty:
        return {k: float("nan") for k in
                ("count", "mean", "p50", "p90", "p95", "p99", "min", "max")}
    return {
        "count": int(s.count()),
        "mean":  float(s.mean()),
        "p50":   float(s.quantile(0.5)),
        "p90":   float(s.quantile(0.9)),
        "p95":   float(s.quantile(0.95)),
        "p99":   float(s.quantile(0.99)),
        "min":   float(s.min()),
        "max":   float(s.max()),
    }


# ── Plot helpers ───────────────────────────────────────────────────────────
def boxplot_by_scale(
    out_path: Path, title: str, ylabel: str, data_by_scale: Dict[int, pd.Series]
) -> None:
    scales = sorted(data_by_scale.keys())
    series_list = [data_by_scale[s].dropna().values for s in scales]
    fig, ax = plt.subplots(figsize=(8, 5))
    ax.boxplot(series_list, labels=[f"{s:,}" for s in scales], showfliers=False)
    ax.set_xlabel("請求量級")
    ax.set_ylabel(ylabel)
    ax.set_title(title)
    ax.grid(True, alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_path, dpi=150)
    plt.close(fig)


def barplot_incomplete(out_path: Path, summary: pd.DataFrame) -> None:
    fig, ax = plt.subplots(figsize=(8, 5))
    bars = ax.bar(
        [f"{int(s):,}" for s in summary["scale"]],
        summary["incomplete_rate"] * 100.0,
        color=["#4C72B0", "#DD8452", "#C44E52"][: len(summary)],
    )
    ax.set_xlabel("請求量級")
    ax.set_ylabel("未完成交易率 (%)")
    ax.set_title("各量級未完成交易率")
    ax.grid(True, axis="y", alpha=0.3)
    for b, rate, total, inc in zip(
        bars, summary["incomplete_rate"], summary["total"], summary["incomplete"]
    ):
        ax.text(
            b.get_x() + b.get_width() / 2,
            b.get_height(),
            f"{rate*100:.2f}%\n({inc}/{total})",
            ha="center", va="bottom", fontsize=9,
        )
    ax.set_ylim(0, max(1.0, summary["incomplete_rate"].max() * 100 * 1.3))
    fig.tight_layout()
    fig.savefig(out_path, dpi=150)
    plt.close(fig)


def histogram_mtls(out_path: Path, by_scale: Dict[int, pd.Series]) -> None:
    fig, ax = plt.subplots(figsize=(8, 5))
    colors = ["#4C72B0", "#DD8452", "#C44E52", "#55A868"]
    for i, (n, s) in enumerate(sorted(by_scale.items())):
        if s.empty:
            continue
        ax.hist(s.values, bins=40, alpha=0.55,
                label=f"{n:,} 筆", color=colors[i % len(colors)])
    ax.set_xlabel("mTLS 握手時間 (ms)")
    ax.set_ylabel("樣本次數")
    ax.set_title("mTLS 握手時間分佈（appconnect_time）")
    ax.legend()
    ax.grid(True, alpha=0.3)
    fig.tight_layout()
    fig.savefig(out_path, dpi=150)
    plt.close(fig)


# ── Excel writer (long-form: one column per scale, value per request) ──────
def write_long_xlsx(
    out_path: Path, sheet_name: str, by_scale: Dict[int, pd.Series]
) -> None:
    cols = {f"{n:,} 筆": pd.Series(by_scale[n].dropna().reset_index(drop=True))
            for n in sorted(by_scale.keys())}
    df = pd.concat(cols, axis=1)
    summary_rows = {
        n: stat_row(by_scale[n]) for n in sorted(by_scale.keys())
    }
    stats_df = pd.DataFrame(summary_rows).T
    stats_df.index.name = "scale"

    with pd.ExcelWriter(out_path, engine="openpyxl") as xw:
        df.to_excel(xw, sheet_name=sheet_name, index=False)
        stats_df.to_excel(xw, sheet_name="統計摘要")


# ── Main ────────────────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--in", dest="in_dir", required=True)
    ap.add_argument("--out", dest="out_dir", required=True)
    ap.add_argument(
        "--scales", default="5000 10000 20000",
        help="space-separated scale list",
    )
    args = ap.parse_args()

    _setup_cjk_font()

    in_dir = Path(args.in_dir)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    scales = [int(x) for x in args.scales.split() if x.strip()]
    if not scales:
        sys.stderr.write("[analyze] no scales specified\n")
        return 1

    per_request_dfs = []
    mtls_dfs = []
    for n in scales:
        df, mdf = assemble_scale(in_dir, n)
        if not df.empty:
            per_request_dfs.append(df)
        if not mdf.empty:
            mtls_dfs.append(mdf)

    if not per_request_dfs:
        sys.stderr.write("[analyze] no data ingested — aborting\n")
        return 1

    all_req = pd.concat(per_request_dfs, ignore_index=True)
    all_mtls = pd.concat(mtls_dfs, ignore_index=True) if mtls_dfs else pd.DataFrame()

    # ── Series by scale for each metric ─────────────────────────────────
    # NEW (Round 2): Gateway 接收請求時間 = server-side gw_proc_ms
    # (controller-in → AMQP basic_publish), NOT client RTT.
    gw_recv = {n: all_req.loc[all_req["scale"] == n, "gw_proc_ms"]
               for n in scales}
    order_done = {n: all_req.loc[all_req["scale"] == n, "order_completion_ms"]
                  for n in scales}
    mtls_hs = {
        n: (all_mtls.loc[all_mtls["scale"] == n, "handshake_ms"]
            if not all_mtls.empty else pd.Series(dtype=float))
        for n in scales
    }

    # ── Outputs ──────────────────────────────────────────────────────────
    write_long_xlsx(out_dir / "Gateway接收請求時間.xlsx", "gw_proc_ms", gw_recv)
    boxplot_by_scale(
        out_dir / "Gateway接收請求時間.png",
        "Gateway 接收請求時間（receive → AMQP publish）", "處理時間 (ms)", gw_recv,
    )

    write_long_xlsx(out_dir / "訂單完成時間.xlsx", "order_completion_ms", order_done)
    boxplot_by_scale(
        out_dir / "訂單完成時間.png",
        "訂單完成時間（請求送出 → Saga 完成）", "延遲 (ms)", order_done,
    )

    # Incomplete summary — Round 2 formula:
    #   未完成率 = rolled_back / total × 100%
    #   總完成 = success + rolled_back
    incomplete_rows = []
    for n in scales:
        slice_df = all_req[all_req["scale"] == n]
        total = len(slice_df)
        success = int(slice_df["completed"].sum())
        rolled_back = int(slice_df["rolled_back"].sum())
        total_completed = success + rolled_back
        incomplete_rows.append({
            "scale": n,
            "total": total,
            "total_completed": total_completed,
            "success": success,
            "rolled_back": rolled_back,
            "incomplete_rate": (rolled_back / total) if total else 0.0,
        })
    summary_inc = pd.DataFrame(incomplete_rows)
    # Provide an "incomplete" alias column for the bar-plot helper
    summary_inc["incomplete"] = summary_inc["rolled_back"]
    summary_inc.to_excel(out_dir / "未完成交易率.xlsx", index=False, sheet_name="incomplete")
    barplot_incomplete(out_dir / "未完成交易率.png", summary_inc)

    write_long_xlsx(out_dir / "mTLS花費時間.xlsx", "handshake_ms", mtls_hs)
    histogram_mtls(out_dir / "mTLS花費時間.png", mtls_hs)

    # ── Summary stats (all four metrics) ─────────────────────────────────
    summary_rows = []
    for n in scales:
        for metric_name, series in [
            ("Gateway接收請求(ms)", gw_recv[n]),
            ("訂單完成(ms)", order_done[n]),
            ("mTLS握手(ms)", mtls_hs[n]),
        ]:
            row = {"scale": n, "metric": metric_name}
            row.update(stat_row(series))
            summary_rows.append(row)
        inc_row = next(r for r in incomplete_rows if r["scale"] == n)
        summary_rows.append({
            "scale": n,
            "metric": "未完成交易率(rolled_back/total)",
            "count": inc_row["total"],
            "mean": inc_row["incomplete_rate"],
            "p50": float("nan"), "p90": float("nan"),
            "p95": float("nan"), "p99": float("nan"),
            "min": float(inc_row["success"]),
            "max": float(inc_row["rolled_back"]),
        })
    summary_df = pd.DataFrame(summary_rows)
    summary_df.to_excel(out_dir / "summary.xlsx", index=False, sheet_name="summary")

    sys.stderr.write(f"[analyze] OK → {out_dir}\n")
    sys.stderr.write(
        f"[analyze] requests={len(all_req)} mtls_samples={len(all_mtls)}\n"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
