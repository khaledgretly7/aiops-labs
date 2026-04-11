import requests
import random
import time
import json
from datetime import datetime, timezone

BASE_URL = "http://localhost:8000/api"

def hit(endpoint, method="GET", json_body=None):
    try:
        if method == "POST":
            requests.post(f"{BASE_URL}/{endpoint}", json=json_body, timeout=15)
        else:
            requests.get(f"{BASE_URL}/{endpoint}", timeout=15)
    except Exception:
        pass

def base_load(duration_seconds=600):
    """Base load: 8-10 minutes, ~3000+ requests"""
    print(f"[{datetime.now()}] Starting BASE LOAD for {duration_seconds}s ...")
    end_time = time.time() + duration_seconds
    count = 0

    while time.time() < end_time:
        r = random.random()

        if r < 0.70:
            hit("normal")
        elif r < 0.85:
            hit("slow")
        elif r < 0.90:
            hit("slow?hard=1")
        elif r < 0.95:
            hit("error")
        elif r < 0.98:
            hit("db")
        else:
            # 50% invalid payloads
            if random.random() < 0.5:
                hit("validate", method="POST", json_body={"email": "bad-email", "age": 5})
            else:
                hit("validate", method="POST", json_body={"email": "user@test.com", "age": 25})

        count += 1
        if count % 100 == 0:
            print(f"  [{datetime.now()}] Base load: {count} requests sent")

        time.sleep(0.18)  # ~5-6 req/sec = 3000+ in 10 min

    print(f"[{datetime.now()}] Base load done. Total: {count} requests")
    return count

def anomaly_load(duration_seconds=120):
    """Anomaly window: 2 minutes — error spike to 40%"""
    print(f"\n[{datetime.now()}] *** ANOMALY WINDOW STARTING ***")
    anomaly_start = datetime.now(timezone.utc).isoformat()
    end_time = time.time() + duration_seconds
    count = 0

    while time.time() < end_time:
        r = random.random()

        if r < 0.40:
            hit("error")           # ERROR SPIKE: raised to 40%
        elif r < 0.65:
            hit("normal")
        elif r < 0.80:
            hit("slow")
        elif r < 0.85:
            hit("slow?hard=1")
        elif r < 0.92:
            hit("db")
        else:
            hit("validate", method="POST", json_body={"email": "bad", "age": 1})

        count += 1
        time.sleep(0.18)

    anomaly_end = datetime.now(timezone.utc).isoformat()
    print(f"[{datetime.now()}] *** ANOMALY WINDOW ENDED *** Total: {count} requests")
    return anomaly_start, anomaly_end, count

def export_logs():
    """Export aiops.log to logs.json"""
    print(f"\n[{datetime.now()}] Exporting logs.json ...")
    entries = []
    log_path = "storage/logs/aiops.log"

    try:
        with open(log_path, "r") as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    raw = json.loads(line)
                    ctx = raw.get("context", {})
                    entries.append({
                        "timestamp":           ctx.get("timestamp"),
                        "correlation_id":      ctx.get("correlation_id"),
                        "method":              ctx.get("method"),
                        "path":                ctx.get("path"),
                        "status_code":         ctx.get("status_code"),
                        "latency_ms":          ctx.get("latency_ms"),
                        "error_category":      ctx.get("error_category"),
                        "severity":            ctx.get("severity"),
                        "client_ip":           ctx.get("client_ip"),
                        "user_agent":          ctx.get("user_agent"),
                        "query":               ctx.get("query"),
                        "payload_size_bytes":  ctx.get("payload_size_bytes"),
                        "response_size_bytes": ctx.get("response_size_bytes"),
                        "route_name":          ctx.get("route_name"),
                        "host":                ctx.get("host"),
                        "build_version":       ctx.get("build_version"),
                    })
                except Exception:
                    pass

        with open("logs.json", "w") as f:
            json.dump(entries, f, indent=2)

        errors = [e for e in entries if e.get("severity") == "error"]
        print(f"  Exported {len(entries)} entries, {len(errors)} errors to logs.json")

    except FileNotFoundError:
        print("  ERROR: aiops.log not found. Make sure Laravel is running.")

if __name__ == "__main__":
    print("=" * 60)
    print("AIOps Traffic Generator")
    print("Make sure Laravel is running: php artisan serve")
    print("=" * 60)

    # Phase 1: Base load (10 minutes)
    base_count = base_load(duration_seconds=600)

    # Phase 2: Anomaly window (2 minutes)
    anomaly_start, anomaly_end, anomaly_count = anomaly_load(duration_seconds=120)

    # Phase 3: Cool down (2 more minutes normal)
    print(f"\n[{datetime.now()}] Cool down phase (2 min)...")
    base_load(duration_seconds=120)

    # Save ground truth
    ground_truth = {
        "anomaly_start_iso":  anomaly_start,
        "anomaly_end_iso":    anomaly_end,
        "anomaly_type":       "ERROR_SPIKE",
        "expected_behavior":  "Error rate rises from ~5% baseline to ~40% during anomaly window. http_errors_total{error_category='SYSTEM_ERROR'} rate spikes sharply. Visible in Grafana error rate panel and error category breakdown panel."
    }

    with open("ground_truth.json", "w") as f:
        json.dump(ground_truth, f, indent=2)

    print(f"\n[{datetime.now()}] ground_truth.json saved:")
    print(json.dumps(ground_truth, indent=2))

    # Export logs
    export_logs()

    print("\n" + "=" * 60)
    print("DONE! Check:")
    print("  - logs.json")
    print("  - ground_truth.json")
    print("  - Grafana dashboard for anomaly spike")
    print("=" * 60)