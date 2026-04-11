# AIOps Engineering Report
**Student:** Khaled Elgreitly
**Date:** April 2026

---

# Lab Work 1: AIOps Observability

## 1. Log Schema Design

Every log record emits the following stable schema. All keys always exist — null is used when a value is unavailable, ensuring downstream ML pipelines never encounter missing columns.

| Field | Type | Reason |
|---|---|---|
| correlation_id | string | Trace a single request across services |
| timestamp | ISO8601 | Absolute time reference for anomaly windows |
| method | string | HTTP verb for RED metrics grouping |
| path | string | Endpoint identity for per-route analysis |
| route_name | string | Laravel named route fallback |
| status_code | int | Primary success/failure signal |
| latency_ms | float | Core performance signal for ML features |
| error_category | string/null | Structured error taxonomy for triage |
| severity | string | info/error — drives alert routing |
| client_ip | string | Source identification |
| user_agent | string | Client type classification |
| query | string/null | Query parameters for failure correlation |
| payload_size_bytes | int | Request body size for anomaly detection |
| response_size_bytes | int | Response size for performance monitoring |
| host | string | Multi-instance differentiation |
| build_version | string | Deployment correlation |

**Key design decision:** `error_category` is populated even for HTTP 200 responses when latency exceeds 4000ms — this is the TIMEOUT_ERROR classification. This allows ML models to detect slow-burn degradation invisible to status-code-only monitoring.

## 2. Metrics Design

### Why these buckets?
Buckets: `0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, +Inf`

- `0.05–0.25s` captures normal endpoint behavior (/api/normal typically < 50ms)
- `0.5–1s` captures normal /api/slow behavior
- `2.5–5s` captures hard-slow timeout range (5–7s sleep)
- `10, +Inf` catches runaway requests

### Why these labels?
Labels: `method`, `path`, `status` for counters; `method`, `path` for histograms.

**No request_id in labels** — this would cause label explosion (one unique series per request = millions of time series = Prometheus OOM). Labels must have bounded cardinality.

### RED Metrics rationale
- **Rate** (`http_requests_total`) — baseline traffic measurement
- **Errors** (`http_errors_total` with `error_category`) — structured failure signal
- **Duration** (`http_request_duration_seconds`) — performance degradation signal

## 3. Anomaly Design

### Why an error spike?
The error spike anomaly (raising /api/error from 5% to 40%) was chosen because:
- It produces a clear, step-function change visible in Grafana within one scrape interval
- It maps directly to a real incident pattern (deployment bug, dependency failure)
- It is detectable by both rule-based (Lab 2) and ML-based (Lab 3) systems

### Ground truth control
The traffic generator records `anomaly_start_iso` and `anomaly_end_iso` in UTC before and after the anomaly window. This provides an objective evaluation baseline for the ML model.

### Timeout classification trick
`/api/slow?hard=1` sleeps 5–7 seconds and returns HTTP 200. The TelemetryMiddleware checks `latency_ms > 4000` **after** the response is received and overrides `error_category` to `TIMEOUT_ERROR`. This demonstrates that status codes alone are insufficient for production observability — latency must be a first-class signal.

---

# Lab Work 2: AIOps Detection Engine

## 1. Baseline Design

Baselines are computed as a rolling average of the last 100 observed samples per endpoint per metric. This design:
- **Avoids hardcoding** — baselines adapt to actual traffic patterns
- **Handles warmup** — early samples are underweighted naturally
- **Persists across restarts** — stored in `storage/aiops/baselines.json`

Per-endpoint baselines are maintained for:
- `request_rate` — normal throughput
- `error_rate` — normal failure rate
- `latency_p95` — normal tail latency

## 2. Anomaly Detection Rules

| Signal | Condition | Rationale |
|---|---|---|
| Latency anomaly | p95 > 3× baseline | 3σ equivalent for latency distributions |
| Error rate anomaly | error_rate > 10% | Absolute threshold — any endpoint above 10% errors is degraded |
| Traffic anomaly | request_rate > 2× baseline | Sudden traffic doubles may indicate bot traffic or cascade |

The 3× multiplier for latency avoids false positives from normal variance while catching genuine degradation. The 10% absolute error threshold catches cold-start scenarios where baseline is near zero.

## 3. Event Correlation Strategy

Multiple anomaly signals from the same detection cycle are correlated into a single incident using this priority matrix:

| Signals present | Incident type |
|---|---|
| Error + Latency | SERVICE_DEGRADATION |
| Error on single endpoint | LOCALIZED_ENDPOINT_FAILURE |
| Error only | ERROR_STORM |
| Latency only | LATENCY_SPIKE |
| Traffic only | TRAFFIC_SURGE |

**Deduplication:** An incident is suppressed if an OPEN incident of the same type affecting the same endpoints already exists. Only `last_seen` is updated. This prevents alert storms during sustained incidents — a common failure mode in naive monitoring systems.

### Incident schema rationale
Every incident has a stable schema with `baseline_values` and `observed_values` side by side. This allows post-incident analysis without querying Prometheus history — the evidence is embedded in the incident record.

---

# Lab Work 3: ML Anomaly Detection

## 1. Feature Engineering

Features are computed over **30-second tumbling windows**. This granularity:
- Is fine enough to capture the anomaly window boundary (2 minutes = 4 windows)
- Is coarse enough to smooth per-request noise
- Produces sufficient samples (≥1500 log entries → hundreds of windows)

| Feature | Rationale |
|---|---|
| avg_latency | Central tendency of response time |
| max_latency | Captures outlier spikes missed by averages |
| latency_std | Variance signal — high std indicates instability |
| p95_latency | Tail latency — service quality indicator |
| request_rate | Traffic volume per window |
| error_rate | Fraction of failed requests |
| errors_per_window | Absolute error count |
| endpoint_frequency | Number of distinct endpoints hit |
| timeout_count | TIMEOUT_ERROR specific counter |
| db_error_count | DATABASE_ERROR specific counter |
| validation_errors | VALIDATION_ERROR specific counter |
| system_errors | SYSTEM_ERROR specific counter |

The error category counts are included separately because a mixed error profile (all categories rising) signals different root causes than a single category spike.

## 2. Model Selection

**Isolation Forest** was chosen over One-Class SVM and LOF for these reasons:

| Criterion | Isolation Forest | One-Class SVM | LOF |
|---|---|---|---|
| Training speed | Fast | Slow (kernel) | Medium |
| High-dimensional data | Excellent | Poor | Good |
| Interpretability | Score-based | Limited | Score-based |
| Contamination control | Explicit parameter | Implicit | Implicit |

`contamination=0.05` matches the expected anomaly rate (anomaly window = ~2 min of ~14 min total ≈ 14%, but model sees only normal training data so 5% contamination accounts for edge cases in normal period).

The model is trained **exclusively on the normal period** (pre-anomaly windows). This is critical — training on anomaly data would teach the model that anomalies are normal.

## 3. Anomaly Detection Performance

The model detects the anomaly window by observing simultaneous elevation in:
- `error_rate` (spike from ~5% to ~40%)
- `system_errors` count per window
- `errors_per_window` absolute count

The anomaly score (negated `score_samples` output) is higher for windows that are more isolated in feature space — exactly the windows with elevated error rates during the anomaly period.

### Evaluation
- **True Positives:** Anomaly windows correctly flagged
- **False Negatives:** Anomaly windows missed (acceptable if < 20%)
- **False Positives:** Normal windows incorrectly flagged (expected ~5% by design)

The red-shaded regions in `anomaly_plot.png` show the ground truth window. Detected anomaly points (red dots) should cluster within these regions.

---

# Deliverables Checklist

| Item | Status |
|---|---|
| GitHub repo + README | ✅ |
| storage/logs/aiops.log | ✅ |
| logs.json (≥1500 entries) | ✅ |
| /metrics endpoint | ✅ |
| docker-compose.yml | ✅ |
| prometheus.yml | ✅ |
| Grafana dashboard JSON | ✅ |
| traffic_generator.py | ✅ |
| ground_truth.json | ✅ |
| aiops:detect command | ✅ |
| incidents.json | ✅ |
| aiops_dataset.csv | ✅ |
| anomaly_predictions.csv | ✅ |
| anomaly_plot.png | ✅ |
| Engineering report | ✅ |