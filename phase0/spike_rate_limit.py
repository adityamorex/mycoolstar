"""
Spike: fire ~110 requests in under a minute against a cheap read-only
endpoint, to see SalesOn's real throttling behavior (documented as
"100 requests per minute per organisation"). Run this LAST, since if it
does trigger a temporary lockout, it shouldn't block any other spike.
"""
import json
import os
import time

import requests
from dotenv import dotenv_values

HERE = os.path.dirname(os.path.abspath(__file__))
ENV = dotenv_values(os.path.join(HERE, ".env.local"))
TOKEN = ENV["SALESON_TOKEN"]
COMPANY_ID = ENV["SALESON_COMPANY_ID"]
BASE_URL = ENV["SALESON_BASE_URL"]
HEADERS = {"Authorization": f"Bearer {TOKEN}"}

N_REQUESTS = 110
URL = BASE_URL + "dashboard/statistics"


def main():
    print(f"Firing {N_REQUESTS} requests to {URL} as fast as possible...")
    results = []
    start = time.monotonic()
    for i in range(N_REQUESTS):
        t0 = time.monotonic()
        resp = requests.get(URL, params={"company_id": COMPANY_ID}, headers=HEADERS, timeout=30)
        elapsed = time.monotonic() - t0
        results.append({
            "n": i + 1,
            "status": resp.status_code,
            "elapsed_s": round(elapsed, 3),
            "retry_after": resp.headers.get("Retry-After"),
        })
        if resp.status_code == 429:
            print(f"  request {i+1}: HTTP 429 (rate limited!) Retry-After={resp.headers.get('Retry-After')}")
        elif (i + 1) % 20 == 0:
            print(f"  request {i+1}: HTTP {resp.status_code}")

    total_elapsed = time.monotonic() - start
    statuses = {}
    for r in results:
        statuses[r["status"]] = statuses.get(r["status"], 0) + 1

    print()
    print(f"=== Done: {N_REQUESTS} requests in {total_elapsed:.1f}s ({N_REQUESTS/total_elapsed:.1f} req/s) ===")
    print("Status code breakdown:", statuses)

    with open(os.path.join(HERE, "logs", "rate_limit_results.json"), "w", encoding="utf-8") as f:
        json.dump({"total_elapsed_s": total_elapsed, "results": results}, f, indent=2)
    print("Full per-request results saved to phase0/logs/rate_limit_results.json")


if __name__ == "__main__":
    main()
