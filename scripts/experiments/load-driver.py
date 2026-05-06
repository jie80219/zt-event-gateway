#!/usr/bin/env python3
"""
Async load driver for the zt-event-gateway POST /api/orders endpoint.

Sends N requests with bounded concurrency, captures per-request gateway
latency and HTTP status. Output is a CSV with one row per request:

    seq,trace_id,t_req_ms,t_resp_ms,http_code,gateway_latency_ms

The script is intentionally read-only — it only POSTs to the public
ingress endpoint and never touches the worker / downstream services.

Usage:
    python3 scripts/experiments/load-driver.py \\
        --count 5000 --concurrency 50 \\
        --out artifacts/2026-04-30_Experimental/raw/5000_gateway.csv

Each request body is small + canonical so the saga always executes the
full 4-step happy path under healthy conditions.
"""

from __future__ import annotations

import argparse
import asyncio
import csv
import os
import sys
import time
import uuid
from dataclasses import dataclass

import aiohttp


@dataclass
class Result:
    seq: int
    trace_id: str
    t_req_ms: int
    t_resp_ms: int
    http_code: int
    gateway_latency_ms: float


async def post_one(
    session: aiohttp.ClientSession,
    url: str,
    seq: int,
    trace_id: str,
    token: str = "",
) -> Result:
    body = {
        "userKey": "1",
        "productList": [{"p_key": (seq % 5) + 1, "amount": 1}],
        "total": 100,
    }
    headers = {
        "Content-Type": "application/json",
        "X-Correlation-Id": trace_id,
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"

    t_req = time.time()
    code = 0
    try:
        async with session.post(url, json=body, headers=headers) as resp:
            await resp.read()
            code = resp.status
    except Exception as e:  # noqa: BLE001 — driver records failure, never raises
        sys.stderr.write(f"[seq={seq}] {type(e).__name__}: {e}\n")
        code = 0
    t_resp = time.time()

    return Result(
        seq=seq,
        trace_id=trace_id,
        t_req_ms=int(t_req * 1000),
        t_resp_ms=int(t_resp * 1000),
        http_code=code,
        gateway_latency_ms=(t_resp - t_req) * 1000.0,
    )


async def run(args: argparse.Namespace) -> int:
    sem = asyncio.Semaphore(args.concurrency)
    results: list[Result] = [None] * args.count  # type: ignore[list-item]
    progress_every = max(args.count // 20, 100)

    timeout = aiohttp.ClientTimeout(total=args.request_timeout)
    connector = aiohttp.TCPConnector(limit=args.concurrency * 2)

    async with aiohttp.ClientSession(timeout=timeout, connector=connector) as session:
        async def worker(seq: int) -> None:
            async with sem:
                trace_id = f"exp-{args.tag}-{seq:06d}-{uuid.uuid4().hex[:8]}"
                results[seq] = await post_one(session, args.url, seq, trace_id, args.token)
                if seq % progress_every == 0 and seq > 0:
                    sys.stderr.write(f"[driver] {seq}/{args.count} sent\n")

        tasks = [asyncio.create_task(worker(i)) for i in range(args.count)]
        await asyncio.gather(*tasks)

    # Sort by t_req_ms so FIFO correlation downstream matches actual send order.
    results.sort(key=lambda r: r.t_req_ms)

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, "w", newline="") as f:
        w = csv.writer(f)
        w.writerow([
            "seq",
            "trace_id",
            "t_req_ms",
            "t_resp_ms",
            "http_code",
            "gateway_latency_ms",
            "round",
        ])
        for i, r in enumerate(results):
            w.writerow([
                i,
                r.trace_id,
                r.t_req_ms,
                r.t_resp_ms,
                r.http_code,
                f"{r.gateway_latency_ms:.3f}",
                args.round,
            ])

    accepted = sum(1 for r in results if r.http_code == 202)
    sys.stderr.write(
        f"[driver] done: {len(results)} sent, {accepted} accepted (202), "
        f"out={args.out}\n",
    )
    return 0 if accepted == len(results) else 1


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser()
    p.add_argument("--url", default=os.environ.get("E2E_GATEWAY_URL", "http://127.0.0.1:8080/api/orders"))
    p.add_argument("--count", type=int, required=True)
    p.add_argument("--concurrency", type=int, default=50)
    p.add_argument("--request-timeout", type=float, default=15.0)
    p.add_argument("--tag", default="loop")
    p.add_argument("--out", required=True)
    p.add_argument(
        "--token",
        default=os.environ.get("LOAD_DRIVER_TOKEN", ""),
        help="Optional Keycloak Bearer token to attach to every request "
             "(needed when Gateway has KEYCLOAK_INGRESS_ENABLED=1).",
    )
    p.add_argument(
        "--round",
        default="warm",
        choices=["warm", "cold"],
        help="Round label written into output CSV for warm/cold A-B comparison.",
    )
    return p.parse_args()


if __name__ == "__main__":
    sys.exit(asyncio.run(run(parse_args())))
