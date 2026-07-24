"""
Shared, tiny HTTP client for the SalesOn API. Every spike script imports this
instead of writing its own request logic - so auth, logging, and retry
behavior is written once and stays consistent across all of them.
"""
import json
import os
import time
from datetime import datetime, timezone

import requests
from dotenv import dotenv_values

HERE = os.path.dirname(os.path.abspath(__file__))
ENV = dotenv_values(os.path.join(HERE, ".env.local"))
LOGS_DIR = os.path.join(HERE, "logs")
os.makedirs(LOGS_DIR, exist_ok=True)

TOKEN = ENV["SALESON_TOKEN"]
COMPANY_ID = ENV["SALESON_COMPANY_ID"]
BASE_URL = ENV["SALESON_BASE_URL"]


def _log(name, method, url, params, body, status, response_text):
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%f")
    path = os.path.join(LOGS_DIR, f"{stamp}_{name}.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump({
            "method": method, "url": url, "params": params, "body": body,
            "status": status, "response_snippet": response_text[:2000],
        }, f, indent=2, default=str)


def _curl_equivalent(method, url, params, body):
    full_url = url
    if params:
        qs = "&".join(f"{k}={v}" for k, v in params.items())
        full_url = f"{url}?{qs}"
    parts = [f"curl -X {method} \"{full_url}\"", f'-H "Authorization: Bearer {TOKEN}"']
    if body is not None:
        parts.append(f"-H \"Content-Type: application/json\" -d '{json.dumps(body)}'")
    return " \\\n  ".join(parts)


def request(method, path, log_name, params=None, body=None, extra_headers=None, retries=1):
    url = BASE_URL + path
    all_params = {"company_id": COMPANY_ID}
    if params:
        all_params.update(params)
    headers = {"Authorization": f"Bearer {TOKEN}"}
    if extra_headers:
        headers.update(extra_headers)

    print(f"--- {log_name}: {method} {path} ---")
    print(_curl_equivalent(method, url, all_params, body))

    attempt = 0
    while True:
        attempt += 1
        resp = requests.request(method, url, params=all_params, json=body, headers=headers, timeout=60)
        if resp.status_code >= 500 and attempt <= retries:
            time.sleep(1)
            continue
        break

    _log(log_name, method, url, all_params, body, resp.status_code, resp.text)
    print(f"    -> HTTP {resp.status_code}")
    return resp


def get(path, log_name, params=None, body=None):
    return request("GET", path, log_name, params=params, body=body)


def post(path, log_name, body=None, params=None):
    return request("POST", path, log_name, params=params, body=body)


def patch(path, log_name, body=None, params=None):
    return request("PATCH", path, log_name, params=params, body=body)


def delete(path, log_name, params=None):
    return request("DELETE", path, log_name, params=params)
