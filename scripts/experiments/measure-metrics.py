#!/usr/bin/env python3
"""Compute per-Run perf metrics from a measure-run.sh raw capture.

Reads the load CSV (per-request gateway latency + http_code) and the worker
perf-log slice (saga start/complete/rollback markers), writes summary.json.
Env: MEASURE_OUT MEASURE_CSV MEASURE_WLOG MEASURE_COUNT MEASURE_CONC MEASURE_ROUND
"""
import csv
import json
import os
import re

OUT = os.environ["MEASURE_OUT"]
CSVF = os.environ["MEASURE_CSV"]
WLOG = os.environ["MEASURE_WLOG"]
COUNT = int(os.environ.get("MEASURE_COUNT", "0"))
CONC = int(os.environ.get("MEASURE_CONC", "0"))
ROUND = os.environ.get("MEASURE_ROUND", "warm")


def pct(a, p):
    if not a:
        return None
    a = sorted(a)
    k = (len(a) - 1) * p / 100.0
    f = int(k)
    c = min(f + 1, len(a) - 1)
    return round(a[f] + (a[c] - a[f]) * (k - f), 2)


rows = list(csv.DictReader(open(CSVF)))
accepted = sum(1 for r in rows if r.get("http_code") == "202")
non202 = sum(1 for r in rows if r.get("http_code") not in ("202", None, ""))
gl = []
for r in rows:
    v = r.get("gateway_latency_ms")
    if v not in (None, "", "nan"):
        try:
            gl.append(float(v))
        except ValueError:
            pass

txt = open(WLOG, encoding="utf-8", errors="replace").read()
step1 = {m.group(2): float(m.group(1))
         for m in re.finditer(r"\[perf-saga-step1\] ts=([\d.]+) orderId=(\S+)", txt)}
comp = {m.group(2): float(m.group(1))
        for m in re.finditer(r"\[perf-saga-complete\] ts=([\d.]+) orderId=(\S+)", txt)}
spans = [(comp[o] - step1[o]) * 1000 for o in comp if o in step1]
rolled = len(re.findall(r"\[perf-saga-rolled-back\]", txt))

summary = {
    "round": ROUND, "count": COUNT, "concurrency": CONC,
    "accepted_202": accepted, "non_202": non202,
    "saga_started": len(step1), "saga_completed": len(comp), "saga_rolled_back": rolled,
    "completion_rate_pct": round(len(comp) / accepted * 100, 2) if accepted else None,
    "incomplete_rate_pct": round((accepted - len(comp)) / accepted * 100, 2) if accepted else None,
    "gateway_latency_ms": {"p50": pct(gl, 50), "p95": pct(gl, 95),
                           "p99": pct(gl, 99), "max": round(max(gl), 2) if gl else None},
    "saga_span_ms": ({"p50": pct(spans, 50), "p95": pct(spans, 95), "p99": pct(spans, 99)}
                     if spans else None),
}
json.dump(summary, open(os.path.join(OUT, "summary.json"), "w"), indent=2)
print(json.dumps(summary, indent=2))
