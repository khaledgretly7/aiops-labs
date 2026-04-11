import json
import pandas as pd
import numpy as np
from datetime import datetime, timedelta
from sklearn.ensemble import IsolationForest
from sklearn.preprocessing import StandardScaler
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
import seaborn as sns
import warnings
warnings.filterwarnings('ignore')

# ─────────────────────────────────────────
# 1. LOAD DATA
# ─────────────────────────────────────────
print("Loading logs.json ...")
with open("logs.json", "r") as f:
    logs = json.load(f)

print(f"  Loaded {len(logs)} log entries")

with open("ground_truth.json", "r") as f:
    ground_truth = json.load(f)

anomaly_start = datetime.fromisoformat(ground_truth["anomaly_start_iso"].replace("Z", "+00:00"))
anomaly_end   = datetime.fromisoformat(ground_truth["anomaly_end_iso"].replace("Z", "+00:00"))
print(f"  Anomaly window: {anomaly_start} → {anomaly_end}")

# ─────────────────────────────────────────
# 2. BUILD DATAFRAME
# ─────────────────────────────────────────
records = []
for log in logs:
    if not log.get("timestamp"):
        continue
    try:
        ts = datetime.fromisoformat(log["timestamp"].replace("Z", "+00:00"))
    except Exception:
        continue

    records.append({
        "timestamp":      ts,
        "endpoint":       log.get("path", "unknown"),
        "latency":        float(log.get("latency_ms", 0)),
        "status_code":    int(log.get("status_code", 200)),
        "error_category": log.get("error_category") or "NONE",
        "is_error":       1 if (log.get("status_code", 200) >= 400 or log.get("error_category")) else 0,
    })

df = pd.DataFrame(records).sort_values("timestamp").reset_index(drop=True)
print(f"  Built DataFrame: {len(df)} rows")

# ─────────────────────────────────────────
# 3. FEATURE ENGINEERING (30-second windows)
# ─────────────────────────────────────────
print("\nEngineering features with 30s windows ...")

df["window"] = df["timestamp"].dt.floor("30s")

features_list = []
for window, group in df.groupby("window"):
    feat = {
        "timestamp":          window,
        "avg_latency":        group["latency"].mean(),
        "max_latency":        group["latency"].max(),
        "latency_std":        group["latency"].std(ddof=0),
        "request_rate":       len(group),
        "error_rate":         group["is_error"].mean(),
        "errors_per_window":  group["is_error"].sum(),
        "endpoint_frequency": group["endpoint"].nunique(),
        "p95_latency":        group["latency"].quantile(0.95),
        "timeout_count":      (group["error_category"] == "TIMEOUT_ERROR").sum(),
        "db_error_count":     (group["error_category"] == "DATABASE_ERROR").sum(),
        "validation_errors":  (group["error_category"] == "VALIDATION_ERROR").sum(),
        "system_errors":      (group["error_category"] == "SYSTEM_ERROR").sum(),
        "in_anomaly_window":  1 if (anomaly_start <= window <= anomaly_end) else 0,
    }
    features_list.append(feat)

features_df = pd.DataFrame(features_list).fillna(0)
print(f"  Feature windows: {len(features_df)}")
print(f"  Anomaly windows: {features_df['in_anomaly_window'].sum()}")

# ─────────────────────────────────────────
# 4. MODEL TRAINING (normal period only)
# ─────────────────────────────────────────
print("\nTraining Isolation Forest on NORMAL period only ...")

feature_cols = [
    "avg_latency", "max_latency", "latency_std",
    "request_rate", "error_rate", "errors_per_window",
    "endpoint_frequency", "p95_latency",
    "timeout_count", "db_error_count",
    "validation_errors", "system_errors",
]

normal_df = features_df[features_df["in_anomaly_window"] == 0]
print(f"  Training samples: {len(normal_df)}")

scaler    = StandardScaler()
X_normal  = scaler.fit_transform(normal_df[feature_cols])

model = IsolationForest(
    n_estimators=200,
    contamination=0.05,
    random_state=42,
    max_samples='auto'
)
model.fit(X_normal)
print("  Model trained.")

# ─────────────────────────────────────────
# 5. PREDICT ON ALL WINDOWS
# ─────────────────────────────────────────
print("\nRunning predictions on all windows ...")

X_all    = scaler.transform(features_df[feature_cols])
preds    = model.predict(X_all)
scores   = model.score_samples(X_all)

features_df["anomaly_score"] = -scores          # higher = more anomalous
features_df["is_anomaly"]    = (preds == -1).astype(int)

# Stats
total     = len(features_df)
detected  = features_df["is_anomaly"].sum()
tp        = features_df[(features_df["is_anomaly"] == 1) & (features_df["in_anomaly_window"] == 1)].shape[0]
fn        = features_df[(features_df["is_anomaly"] == 0) & (features_df["in_anomaly_window"] == 1)].shape[0]
fp        = features_df[(features_df["is_anomaly"] == 1) & (features_df["in_anomaly_window"] == 0)].shape[0]

print(f"  Total windows:    {total}")
print(f"  Detected anomaly: {detected}")
print(f"  True positives:   {tp}")
print(f"  False negatives:  {fn}")
print(f"  False positives:  {fp}")

# ─────────────────────────────────────────
# 6. SAVE PREDICTIONS CSV
# ─────────────────────────────────────────
output = features_df[["timestamp", "anomaly_score", "is_anomaly", "in_anomaly_window",
                       "avg_latency", "error_rate", "request_rate"]].copy()
output["timestamp"] = output["timestamp"].astype(str)
output.to_csv("anomaly_predictions.csv", index=False)
print("\nSaved anomaly_predictions.csv")

# ─────────────────────────────────────────
# 7. SAVE DATASET CSV
# ─────────────────────────────────────────
features_df["timestamp"] = features_df["timestamp"].astype(str)
features_df.to_csv("aiops_dataset.csv", index=False)
print("Saved aiops_dataset.csv")

# ─────────────────────────────────────────
# 8. VISUALIZATIONS
# ─────────────────────────────────────────
print("\nGenerating plots ...")

features_df["timestamp"] = pd.to_datetime(features_df["timestamp"])
anomaly_mask  = features_df["is_anomaly"] == 1
window_mask   = features_df["in_anomaly_window"] == 1

fig, axes = plt.subplots(4, 1, figsize=(16, 18), sharex=True)
fig.suptitle("AIOps ML Anomaly Detection Results", fontsize=16, fontweight='bold')

# Panel 1: Latency timeline
ax1 = axes[0]
ax1.plot(features_df["timestamp"], features_df["avg_latency"],
         color='steelblue', linewidth=1, label='Avg Latency (ms)')
ax1.plot(features_df["timestamp"], features_df["p95_latency"],
         color='orange', linewidth=1, linestyle='--', label='P95 Latency (ms)')
ax1.scatter(features_df[anomaly_mask]["timestamp"],
            features_df[anomaly_mask]["avg_latency"],
            color='red', s=60, zorder=5, label='Anomaly detected')
# Shade anomaly window
for _, row in features_df[window_mask].iterrows():
    ax1.axvspan(row["timestamp"], row["timestamp"] + timedelta(seconds=30),
                alpha=0.15, color='red')
ax1.set_ylabel("Latency (ms)")
ax1.set_title("Latency Timeline")
ax1.legend(loc='upper left', fontsize=8)
ax1.grid(True, alpha=0.3)

# Panel 2: Error rate timeline
ax2 = axes[1]
ax2.plot(features_df["timestamp"], features_df["error_rate"] * 100,
         color='crimson', linewidth=1.5, label='Error Rate %')
ax2.scatter(features_df[anomaly_mask]["timestamp"],
            features_df[anomaly_mask]["error_rate"] * 100,
            color='darkred', s=60, zorder=5, label='Anomaly detected')
ax2.axhline(y=10, color='orange', linestyle='--', linewidth=1, label='10% threshold')
for _, row in features_df[window_mask].iterrows():
    ax2.axvspan(row["timestamp"], row["timestamp"] + timedelta(seconds=30),
                alpha=0.15, color='red')
ax2.set_ylabel("Error Rate (%)")
ax2.set_title("Error Rate Timeline")
ax2.legend(loc='upper left', fontsize=8)
ax2.grid(True, alpha=0.3)

# Panel 3: Anomaly score timeline
ax3 = axes[2]
ax3.plot(features_df["timestamp"], features_df["anomaly_score"],
         color='purple', linewidth=1, label='Anomaly Score')
ax3.scatter(features_df[anomaly_mask]["timestamp"],
            features_df[anomaly_mask]["anomaly_score"],
            color='red', s=60, zorder=5, label='Flagged anomaly')
threshold = features_df[~anomaly_mask]["anomaly_score"].quantile(0.95)
ax3.axhline(y=threshold, color='orange', linestyle='--', linewidth=1, label='95th pct threshold')
for _, row in features_df[window_mask].iterrows():
    ax3.axvspan(row["timestamp"], row["timestamp"] + timedelta(seconds=30),
                alpha=0.15, color='red')
ax3.set_ylabel("Anomaly Score")
ax3.set_title("Isolation Forest Anomaly Score (higher = more anomalous)")
ax3.legend(loc='upper left', fontsize=8)
ax3.grid(True, alpha=0.3)

# Panel 4: Request rate
ax4 = axes[3]
ax4.plot(features_df["timestamp"], features_df["request_rate"],
         color='green', linewidth=1, label='Request Rate (per 30s window)')
ax4.scatter(features_df[anomaly_mask]["timestamp"],
            features_df[anomaly_mask]["request_rate"],
            color='red', s=60, zorder=5, label='Anomaly detected')
for _, row in features_df[window_mask].iterrows():
    ax4.axvspan(row["timestamp"], row["timestamp"] + timedelta(seconds=30),
                alpha=0.15, color='red')
ax4.set_ylabel("Requests per window")
ax4.set_title("Request Rate Timeline")
ax4.set_xlabel("Time")
ax4.legend(loc='upper left', fontsize=8)
ax4.grid(True, alpha=0.3)

# Add legend for anomaly window shading
red_patch = mpatches.Patch(color='red', alpha=0.15, label='Ground truth anomaly window')
fig.legend(handles=[red_patch], loc='lower center', fontsize=9)

plt.tight_layout(rect=[0, 0.02, 1, 1])
plt.savefig("anomaly_plot.png", dpi=150, bbox_inches='tight')
print("Saved anomaly_plot.png")

# Bonus: confusion-style summary bar chart
fig2, ax = plt.subplots(figsize=(8, 4))
categories = ['True Positives\n(anomaly in window)', 'False Negatives\n(missed in window)',
              'False Positives\n(outside window)']
values = [tp, fn, fp]
colors = ['green', 'red', 'orange']
bars = ax.bar(categories, values, color=colors, edgecolor='black', width=0.5)
for bar, val in zip(bars, values):
    ax.text(bar.get_x() + bar.get_width()/2, bar.get_height() + 0.2,
            str(val), ha='center', fontweight='bold', fontsize=12)
ax.set_title("Detection Performance Summary", fontsize=14, fontweight='bold')
ax.set_ylabel("Window Count")
ax.grid(True, axis='y', alpha=0.3)
plt.tight_layout()
plt.savefig("detection_performance.png", dpi=150, bbox_inches='tight')
print("Saved detection_performance.png")

plt.show()

print("\n" + "="*60)
print("LAB 3 COMPLETE")
print(f"  Dataset:     aiops_dataset.csv      ({len(features_df)} windows)")
print(f"  Predictions: anomaly_predictions.csv")
print(f"  Plots:       anomaly_plot.png, detection_performance.png")
print(f"  Model:       Isolation Forest (n=200, contamination=0.05)")
print(f"  TP={tp}  FN={fn}  FP={fp}")
print("="*60)