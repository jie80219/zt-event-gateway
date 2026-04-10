# LSVID Stress Summary

Run timestamp: `sample`

| Profile | Success | Failed | Throughput (req/s) | p50 (s) | p95 (s) | p99 (s) |
|---|---|---|---|---|---|---|
| A — baseline (LSVID off) | 500 | 0 | 152.3 (+0.0%) | 0.042 (+0.0%) | 0.072 (+0.0%) | 0.091 (+0.0%) |
| B — minting only (fail-open) | 500 | 0 | 148.7 (-2.4%) | 0.044 (+4.8%) | 0.076 (+5.6%) | 0.098 (+7.7%) |
| C — fail-closed | 500 | 0 | 146.1 (-4.1%) | 0.045 (+7.1%) | 0.078 (+8.3%) | 0.101 (+11.0%) |
| D — fail-closed + re-validate | 500 | 0 | 144.9 (-4.9%) | 0.046 (+9.5%) | 0.079 (+9.7%) | 0.103 (+13.2%) |

Deltas are computed against profile A (baseline, LSVID off).
