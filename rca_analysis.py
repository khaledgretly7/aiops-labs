import json
import pandas as pd
import numpy as np
from datetime import datetime, timedelta
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
import matplotlib.gridspec as gridspec
from collections import Counter
import warnings
warnings.filterwarnings('ignore')

# ─────────────────────────────────────────
# 1. LOAD DATA
# ─────────────────────────────────────────
print("="*60)
print("AIOps Root Cause Analysis Engine")
print("="*60)

with open("logs.json", "r") as f:
    logs = json.load(f)

with open("ground_truth.json", "r") as f:
    ground_truth = json.load(f)

with open("anomaly_predictions.csv", "r") as f:
    predictions = pd.read_csv(f)

print(f"Loaded {len(logs)} log entries")

anomaly_start = datetime.fromisoformat(
    ground_truth["anomaly_start_iso"].replace("Z", "+00:00")).replace(tzinfo=None)
anomaly_end = datetime.fromisoformat(
    ground_truth["anomaly_end_iso"].replace("Z", "+00:00")).replace(tzinfo=None)

print(f"Anomaly window: {anomaly_start} -> {anomaly_end}")

# ─────────────────────────────────────────
# 2. BUILD DATAFRAME
# ─────────────────────────────────────────
records = []
for log in logs:
    if not log.get("timestamp"):
        continue
    try:
        ts = datetime.fromisoformat(log["timestamp"].replace("Z", "+00:00")).replace(tzinfo=None)
    except Exception:
        continue
    records.append({
        "timestamp":      ts,
        "endpoint":       log.get("path", "unknown"),
        "latency_ms":     float(log.get("latency_ms", 0)),
        "status_code":    int(log.get("status_code", 200)),
        "error_category": log.get("error_category") or "NONE",
        "is_error":       1 if log.get("status_code", 200) >= 400 or log.get("error_category") else 0,
        "severity":       log.get("severity", "info"),
    })

df = pd.DataFrame(records).sort_values("timestamp").reset_index(drop=True)

# Label windows
df["in_anomaly"] = df["timestamp"].apply(
    lambda t: anomaly_start <= t <= anomaly_end)

normal_df  = df[~df["in_anomaly"]]
anomaly_df = df[df["in_anomaly"]]

print(f"Normal period logs:  {len(normal_df)}")
print(f"Anomaly period logs: {len(anomaly_df)}")

# ─────────────────────────────────────────
# 3. SIGNAL ANALYSIS
# ─────────────────────────────────────────
print("\n--- SIGNAL ANALYSIS ---")

# Latency
normal_latency  = normal_df["latency_ms"].mean()
anomaly_latency = anomaly_df["latency_ms"].mean() if len(anomaly_df) else 0
latency_ratio   = anomaly_latency / normal_latency if normal_latency > 0 else 1

# Error rate
normal_error_rate  = normal_df["is_error"].mean()
anomaly_error_rate = anomaly_df["is_error"].mean() if len(anomaly_df) else 0
error_ratio        = anomaly_error_rate / normal_error_rate if normal_error_rate > 0 else 999

# Request rate (per minute)
normal_duration  = max((normal_df["timestamp"].max() - normal_df["timestamp"].min()).seconds / 60, 1)
anomaly_duration = max((anomaly_end - anomaly_start).seconds / 60, 1)
normal_rps       = len(normal_df) / normal_duration
anomaly_rps      = len(anomaly_df) / anomaly_duration if len(anomaly_df) else 0
rps_ratio        = anomaly_rps / normal_rps if normal_rps > 0 else 1

print(f"  Latency   — normal: {normal_latency:.1f}ms  anomaly: {anomaly_latency:.1f}ms  ratio: {latency_ratio:.2f}x")
print(f"  Error rate— normal: {normal_error_rate*100:.1f}%   anomaly: {anomaly_error_rate*100:.1f}%   ratio: {error_ratio:.2f}x")
print(f"  Req/min   — normal: {normal_rps:.1f}      anomaly: {anomaly_rps:.1f}      ratio: {rps_ratio:.2f}x")

# ─────────────────────────────────────────
# 4. ENDPOINT ATTRIBUTION
# ─────────────────────────────────────────
print("\n--- ENDPOINT ATTRIBUTION ---")

endpoint_scores = {}
for endpoint in df["endpoint"].unique():
    ep_normal  = normal_df[normal_df["endpoint"] == endpoint]
    ep_anomaly = anomaly_df[anomaly_df["endpoint"] == endpoint]
    if len(ep_anomaly) == 0:
        continue

    ep_normal_err  = ep_normal["is_error"].mean() if len(ep_normal) > 0 else 0
    ep_anomaly_err = ep_anomaly["is_error"].mean()
    ep_err_delta   = ep_anomaly_err - ep_normal_err

    ep_normal_lat  = ep_normal["latency_ms"].mean() if len(ep_normal) > 0 else 0
    ep_anomaly_lat = ep_anomaly["latency_ms"].mean()
    ep_lat_delta   = ep_anomaly_lat - ep_normal_lat

    ep_anomaly_vol = len(ep_anomaly)
    ep_normal_vol  = len(ep_normal)

    # Composite attribution score
    score = (ep_err_delta * 100) + (ep_lat_delta * 0.01) + (ep_anomaly_vol * 0.5)
    endpoint_scores[endpoint] = {
        "score":          round(score, 2),
        "error_delta":    round(ep_err_delta * 100, 2),
        "latency_delta":  round(ep_lat_delta, 2),
        "anomaly_volume": ep_anomaly_vol,
        "normal_volume":  ep_normal_vol,
        "anomaly_error_rate": round(ep_anomaly_err * 100, 2),
        "normal_error_rate":  round(ep_normal_err * 100, 2),
    }

endpoint_scores = dict(sorted(endpoint_scores.items(),
                               key=lambda x: x[1]["score"], reverse=True))

for ep, s in endpoint_scores.items():
    print(f"  {ep}")
    print(f"    score={s['score']}  err_delta={s['error_delta']}%  "
          f"lat_delta={s['latency_delta']}ms  vol={s['anomaly_volume']}")

root_cause_endpoint = list(endpoint_scores.keys())[0] if endpoint_scores else "unknown"
root_cause_data     = endpoint_scores.get(root_cause_endpoint, {})

print(f"\n  ROOT CAUSE ENDPOINT: {root_cause_endpoint}")

# ─────────────────────────────────────────
# 5. ERROR CATEGORY ANALYSIS
# ─────────────────────────────────────────
print("\n--- ERROR CATEGORY ANALYSIS ---")

normal_cats  = Counter(normal_df[normal_df["is_error"]==1]["error_category"])
anomaly_cats = Counter(anomaly_df[anomaly_df["is_error"]==1]["error_category"])

print("  Normal period error distribution:")
for cat, count in normal_cats.most_common():
    print(f"    {cat}: {count}")

print("  Anomaly period error distribution:")
for cat, count in anomaly_cats.most_common():
    print(f"    {cat}: {count}")

primary_signal = anomaly_cats.most_common(1)[0][0] if anomaly_cats else "SYSTEM_ERROR"
print(f"\n  PRIMARY ERROR SIGNAL: {primary_signal}")

# ─────────────────────────────────────────
# 6. INCIDENT TIMELINE
# ─────────────────────────────────────────
print("\n--- INCIDENT TIMELINE ---")

# 30-second windows for timeline
df["window"] = df["timestamp"].dt.floor("30s")
timeline_df = df.groupby("window").agg(
    request_count=("is_error", "count"),
    error_count=("is_error", "sum"),
    avg_latency=("latency_ms", "mean"),
    error_rate=("is_error", "mean"),
).reset_index()
timeline_df["in_anomaly"] = timeline_df["window"].apply(
    lambda t: anomaly_start <= t <= anomaly_end)

# Detect peak
anomaly_windows = timeline_df[timeline_df["in_anomaly"]]
peak_window = anomaly_windows.loc[anomaly_windows["error_rate"].idxmax()] \
    if len(anomaly_windows) > 0 else None

# Recovery = first window after anomaly where error_rate drops below 10%
post_anomaly = timeline_df[timeline_df["window"] > anomaly_end]
recovery_window = post_anomaly[post_anomaly["error_rate"] < 0.10]
recovery_time = recovery_window.iloc[0]["window"] if len(recovery_window) > 0 else anomaly_end

timeline = [
    {
        "phase":       "NORMAL",
        "time":        str(df["timestamp"].min().replace(microsecond=0)),
        "description": f"System operating normally. Avg latency {normal_latency:.0f}ms, "
                       f"error rate {normal_error_rate*100:.1f}%",
        "error_rate":  round(normal_error_rate * 100, 2),
        "avg_latency": round(normal_latency, 2),
    },
    {
        "phase":       "ANOMALY_START",
        "time":        str(anomaly_start.replace(microsecond=0)),
        "description": f"Error rate begins rising on {root_cause_endpoint}. "
                       f"Traffic pattern shifts.",
        "error_rate":  round(anomaly_error_rate * 100, 2),
        "avg_latency": round(anomaly_latency, 2),
    },
    {
        "phase":       "PEAK_INCIDENT",
        "time":        str(peak_window["window"].replace(microsecond=0)) if peak_window is not None else str(anomaly_start),
        "description": f"Peak error rate {peak_window['error_rate']*100:.1f}% reached. "
                       f"{primary_signal} dominating." if peak_window is not None else "Peak reached.",
        "error_rate":  round(float(peak_window["error_rate"]) * 100, 2) if peak_window is not None else 0,
        "avg_latency": round(float(peak_window["avg_latency"]), 2) if peak_window is not None else 0,
    },
    {
        "phase":       "ANOMALY_END",
        "time":        str(anomaly_end.replace(microsecond=0)),
        "description": "Injected anomaly window ends. Error rate begins declining.",
        "error_rate":  round(anomaly_error_rate * 100, 2),
        "avg_latency": round(anomaly_latency, 2),
    },
    {
        "phase":       "RECOVERY",
        "time":        str(recovery_time.replace(microsecond=0)),
        "description": "Error rate drops below 10%. System returns to normal baseline.",
        "error_rate":  round(normal_error_rate * 100, 2),
        "avg_latency": round(normal_latency, 2),
    },
]

for phase in timeline:
    print(f"  [{phase['phase']}] {phase['time']} — {phase['description']}")

# ─────────────────────────────────────────
# 7. CONFIDENCE SCORE
# ─────────────────────────────────────────
signals_triggered = 0
if error_ratio > 3:    signals_triggered += 1
if latency_ratio > 2:  signals_triggered += 1
if rps_ratio > 1.5:    signals_triggered += 1
if root_cause_data.get("error_delta", 0) > 20: signals_triggered += 1
if len(anomaly_cats) > 0: signals_triggered += 1

confidence = min(round((signals_triggered / 5) * 100, 1), 99.0)

# ─────────────────────────────────────────
# 8. SAVE RCA REPORT JSON
# ─────────────────────────────────────────
rca_report = {
    "incident_id":         f"RCA-{anomaly_start.strftime('%Y%m%d-%H%M')}",
    "generated_at":        datetime.now().isoformat(),
    "anomaly_window": {
        "start": str(anomaly_start),
        "end":   str(anomaly_end),
        "type":  ground_truth.get("anomaly_type", "ERROR_SPIKE"),
    },
    "root_cause_endpoint": root_cause_endpoint,
    "primary_signal":      primary_signal,
    "supporting_evidence": {
        "error_rate_normal":       f"{normal_error_rate*100:.1f}%",
        "error_rate_anomaly":      f"{anomaly_error_rate*100:.1f}%",
        "error_rate_ratio":        f"{error_ratio:.2f}x",
        "latency_normal_ms":       round(normal_latency, 1),
        "latency_anomaly_ms":      round(anomaly_latency, 1),
        "latency_ratio":           f"{latency_ratio:.2f}x",
        "request_rate_normal_rpm": round(normal_rps, 1),
        "request_rate_anomaly_rpm":round(anomaly_rps, 1),
        "endpoint_error_delta":    f"{root_cause_data.get('error_delta', 0):.1f}%",
        "dominant_error_category": primary_signal,
        "error_category_counts":   dict(anomaly_cats),
        "endpoint_attribution":    endpoint_scores,
    },
    "confidence_score":    confidence,
    "incident_timeline":   timeline,
    "recommended_action":  (
        f"Investigate {root_cause_endpoint} for elevated {primary_signal}. "
        f"Error rate increased {error_ratio:.1f}x during anomaly window. "
        "Check application logs for root exception, verify downstream dependencies, "
        "and review recent deployments (BUILD_VERSION in logs)."
    ),
    "signals_summary": {
        "latency_anomalous":     latency_ratio > 2,
        "error_rate_anomalous":  error_ratio > 3,
        "traffic_anomalous":     rps_ratio > 1.5,
        "signals_triggered":     signals_triggered,
        "total_signals":         5,
    }
}

with open("rca_report.json", "w") as f:
    json.dump(rca_report, f, indent=2)

print(f"\n  Confidence score: {confidence}%")
print("  Saved rca_report.json")

# ─────────────────────────────────────────
# 9. TIMELINE VISUALIZATION
# ─────────────────────────────────────────
print("\nGenerating visualizations ...")

fig = plt.figure(figsize=(18, 14))
fig.suptitle(f"Root Cause Analysis — {rca_report['incident_id']}",
             fontsize=16, fontweight='bold', y=0.98)

gs = gridspec.GridSpec(3, 2, figure=fig, hspace=0.45, wspace=0.35)

# Colors
NORMAL_COLOR  = '#2ecc71'
ANOMALY_COLOR = '#e74c3c'
LATENCY_COLOR = '#3498db'
RPS_COLOR     = '#9b59b6'

def shade_anomaly(ax):
    ax.axvspan(anomaly_start, anomaly_end, alpha=0.15, color=ANOMALY_COLOR, label='Anomaly window')

# ── Panel 1: Error rate timeline ──
ax1 = fig.add_subplot(gs[0, :])
ax1.plot(timeline_df["window"], timeline_df["error_rate"] * 100,
         color=ANOMALY_COLOR, linewidth=2, label='Error Rate %')
ax1.fill_between(timeline_df["window"], timeline_df["error_rate"] * 100,
                 alpha=0.2, color=ANOMALY_COLOR)
shade_anomaly(ax1)
ax1.axhline(y=10, color='orange', linestyle='--', linewidth=1.5, label='10% threshold')
ax1.axhline(y=normal_error_rate*100, color=NORMAL_COLOR, linestyle=':', linewidth=1.5,
            label=f'Normal baseline ({normal_error_rate*100:.1f}%)')
# Mark phases
for phase in timeline:
    try:
        t = datetime.fromisoformat(phase["time"])
        if phase["phase"] in ("ANOMALY_START", "PEAK_INCIDENT", "RECOVERY"):
            ax1.axvline(x=t, color='gray', linestyle='--', alpha=0.7, linewidth=1)
            ax1.text(t, ax1.get_ylim()[1]*0.9 if ax1.get_ylim()[1] > 0 else 50,
                     phase["phase"], fontsize=7, rotation=45, ha='left', color='gray')
    except:
        pass
ax1.set_title("Error Rate Timeline with Anomaly Window", fontweight='bold')
ax1.set_ylabel("Error Rate (%)")
ax1.legend(fontsize=8, loc='upper left')
ax1.grid(True, alpha=0.3)

# ── Panel 2: Latency timeline ──
ax2 = fig.add_subplot(gs[1, 0])
ax2.plot(timeline_df["window"], timeline_df["avg_latency"],
         color=LATENCY_COLOR, linewidth=2, label='Avg Latency (ms)')
shade_anomaly(ax2)
ax2.axhline(y=normal_latency, color=NORMAL_COLOR, linestyle=':', linewidth=1.5,
            label=f'Normal ({normal_latency:.0f}ms)')
ax2.set_title("Latency Timeline", fontweight='bold')
ax2.set_ylabel("Avg Latency (ms)")
ax2.legend(fontsize=8)
ax2.grid(True, alpha=0.3)

# ── Panel 3: Request rate ──
ax3 = fig.add_subplot(gs[1, 1])
ax3.plot(timeline_df["window"], timeline_df["request_count"],
         color=RPS_COLOR, linewidth=2, label='Requests per window')
shade_anomaly(ax3)
ax3.set_title("Request Volume Timeline", fontweight='bold')
ax3.set_ylabel("Requests / 30s window")
ax3.legend(fontsize=8)
ax3.grid(True, alpha=0.3)

# ── Panel 4: Error category breakdown ──
ax4 = fig.add_subplot(gs[2, 0])
all_cats = list(set(list(normal_cats.keys()) + list(anomaly_cats.keys())))
x = np.arange(len(all_cats))
w = 0.35
normal_vals  = [normal_cats.get(c, 0) for c in all_cats]
anomaly_vals = [anomaly_cats.get(c, 0) for c in all_cats]
bars1 = ax4.bar(x - w/2, normal_vals,  w, label='Normal',  color=NORMAL_COLOR,  alpha=0.8)
bars2 = ax4.bar(x + w/2, anomaly_vals, w, label='Anomaly', color=ANOMALY_COLOR, alpha=0.8)
ax4.set_xticks(x)
ax4.set_xticklabels([c.replace('_', '\n') for c in all_cats], fontsize=8)
ax4.set_title("Error Category Distribution\nNormal vs Anomaly", fontweight='bold')
ax4.set_ylabel("Count")
ax4.legend(fontsize=8)
ax4.grid(True, axis='y', alpha=0.3)

# ── Panel 5: Endpoint attribution ──
ax5 = fig.add_subplot(gs[2, 1])
if endpoint_scores:
    ep_names = [e.split('/')[-1] or e for e in list(endpoint_scores.keys())[:6]]
    ep_err   = [endpoint_scores[e]["anomaly_error_rate"] for e in list(endpoint_scores.keys())[:6]]
    bar_colors = [ANOMALY_COLOR if e == root_cause_endpoint.split('/')[-1]
                  else LATENCY_COLOR for e in ep_names]
    bars = ax5.barh(ep_names, ep_err, color=bar_colors, alpha=0.85, edgecolor='white')
    ax5.set_xlabel("Error Rate During Anomaly (%)")
    ax5.set_title("Endpoint Error Rate During Anomaly\n(red = root cause)", fontweight='bold')
    ax5.grid(True, axis='x', alpha=0.3)
    for bar, val in zip(bars, ep_err):
        ax5.text(val + 0.5, bar.get_y() + bar.get_height()/2,
                 f'{val:.1f}%', va='center', fontsize=8)

plt.savefig("rca_timeline.png", dpi=150, bbox_inches='tight', facecolor='white')
print("Saved rca_timeline.png")

# ── Summary text box ──
print("\n" + "="*60)
print("RCA SUMMARY")
print("="*60)
print(f"  Incident ID:       {rca_report['incident_id']}")
print(f"  Root Cause:        {root_cause_endpoint}")
print(f"  Primary Signal:    {primary_signal}")
print(f"  Error Rate Change: {normal_error_rate*100:.1f}% → {anomaly_error_rate*100:.1f}%")
print(f"  Latency Change:    {normal_latency:.0f}ms → {anomaly_latency:.0f}ms")
print(f"  Confidence:        {confidence}%")
print(f"  Action:            {rca_report['recommended_action'][:80]}...")
print("="*60)
print("\nFiles saved: rca_report.json, rca_timeline.png")