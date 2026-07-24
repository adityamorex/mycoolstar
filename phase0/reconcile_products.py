"""
Phase 0 data-discovery spike: pull the real product catalogs from both
SalesOn and WooCommerce, and compare them by name to see what's actually
missing/mismatched on each side.

This is a throwaway script - its only job is to answer:
  - How many products exist in SalesOn vs. on the live WooCommerce site?
  - Which SalesOn products have no matching WooCommerce product (missing from site)?
  - Which WooCommerce products have no matching SalesOn product (orphaned/no ERP backing)?

Run: python phase0/reconcile_products.py
"""
import json
import os
import re
import sys
from datetime import datetime, timezone
from difflib import SequenceMatcher

import requests
from dotenv import dotenv_values

HERE = os.path.dirname(os.path.abspath(__file__))
ENV = dotenv_values(os.path.join(HERE, ".env.local"))
LOGS_DIR = os.path.join(HERE, "logs")
os.makedirs(LOGS_DIR, exist_ok=True)

SALESON_TOKEN = ENV["SALESON_TOKEN"]
SALESON_COMPANY_ID = ENV["SALESON_COMPANY_ID"]
SALESON_BASE_URL = ENV["SALESON_BASE_URL"]

WC_KEY = ENV["WOOCOMMERCE_CONSUMER_KEY"]
WC_SECRET = ENV["WOOCOMMERCE_CONSUMER_SECRET"]
WC_BASE_URL = ENV["WOOCOMMERCE_BASE_URL"]


def log_call(name, method, url, params, status, body_snippet):
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%f")
    path = os.path.join(LOGS_DIR, f"{stamp}_{name}.json")
    with open(path, "w", encoding="utf-8") as f:
        json.dump(
            {"method": method, "url": url, "params": params, "status": status, "body_snippet": body_snippet},
            f, indent=2, default=str,
        )


def fetch_saleson_products():
    print("Fetching SalesOn product catalog (GET /products)...")
    url = SALESON_BASE_URL + "products"
    params = {"company_id": SALESON_COMPANY_ID, "with_unit": "false"}
    headers = {"Authorization": f"Bearer {SALESON_TOKEN}"}
    resp = requests.get(url, params=params, headers=headers, timeout=60)
    log_call("saleson_products", "GET", url, params, resp.status_code, resp.text[:500])
    resp.raise_for_status()
    data = resp.json()
    products = data.get("products", [])
    print(f"  -> got {len(products)} products from SalesOn in one call.")
    return products


def fetch_woocommerce_products():
    print("Fetching WooCommerce product catalog (paginated)...")
    all_products = []
    page = 1
    while True:
        url = WC_BASE_URL + "products"
        params = {"per_page": 100, "page": page, "status": "any"}
        resp = requests.get(url, params=params, auth=(WC_KEY, WC_SECRET), timeout=60)
        log_call(f"woo_products_page{page}", "GET", url, params, resp.status_code, resp.text[:500])
        resp.raise_for_status()
        batch = resp.json()
        if not batch:
            break
        all_products.extend(batch)
        print(f"  -> page {page}: {len(batch)} products (running total {len(all_products)})")
        if len(batch) < 100:
            break
        page += 1
    return all_products


def normalize(name):
    name = name.lower().strip()
    name = re.sub(r"[^a-z0-9\s]", "", name)
    name = re.sub(r"\s+", " ", name)
    return name


def best_match(target_norm, candidates_norm):
    """Return (matched_key, score) for the closest normalized-name match, or (None, 0)."""
    best_key, best_score = None, 0.0
    for key in candidates_norm:
        score = SequenceMatcher(None, target_norm, key).ratio()
        if score > best_score:
            best_key, best_score = key, score
    return best_key, best_score


def main():
    saleson_products = fetch_saleson_products()
    woo_products = fetch_woocommerce_products()

    saleson_by_norm = {}
    for p in saleson_products:
        saleson_by_norm.setdefault(normalize(p["name"]), []).append(p)

    woo_by_norm = {}
    for p in woo_products:
        woo_by_norm.setdefault(normalize(p["name"]), []).append(p)

    MATCH_THRESHOLD = 0.85  # near-exact name match required

    matched = []
    saleson_missing_from_woo = []
    for norm_name, s_group in saleson_by_norm.items():
        if norm_name in woo_by_norm:
            matched.append((norm_name, s_group, woo_by_norm[norm_name]))
        else:
            key, score = best_match(norm_name, woo_by_norm.keys())
            if key and score >= MATCH_THRESHOLD:
                matched.append((norm_name, s_group, woo_by_norm[key]))
            else:
                saleson_missing_from_woo.append(s_group)

    matched_woo_norms = set()
    for norm_name, _, woo_group in matched:
        for p in woo_group:
            matched_woo_norms.add(normalize(p["name"]))

    woo_orphans = [p for p in woo_products if normalize(p["name"]) not in matched_woo_norms]

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "totals": {
            "saleson_products": len(saleson_products),
            "woocommerce_products": len(woo_products),
            "matched_by_name": len(matched),
            "saleson_missing_from_site": len(saleson_missing_from_woo),
            "woocommerce_orphans_no_saleson_match": len(woo_orphans),
        },
        "saleson_missing_from_site_sample": [
            {"id": g[0]["id"], "name": g[0]["name"], "group_id": g[0].get("group_id"), "stock": g[0].get("stock")}
            for g in saleson_missing_from_woo[:50]
        ],
        "woocommerce_orphans_sample": [
            {"id": p["id"], "name": p["name"], "sku": p.get("sku"), "categories": [c["name"] for c in p.get("categories", [])]}
            for p in woo_orphans[:50]
        ],
    }

    out_path = os.path.join(HERE, "logs", "reconciliation_report.json")
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, default=str)

    print("\n=== RECONCILIATION SUMMARY ===")
    print(f"SalesOn products:              {report['totals']['saleson_products']}")
    print(f"WooCommerce products:          {report['totals']['woocommerce_products']}")
    print(f"Matched by name:               {report['totals']['matched_by_name']}")
    print(f"SalesOn NOT on website:        {report['totals']['saleson_missing_from_site']}")
    print(f"Website products w/ no SalesOn match: {report['totals']['woocommerce_orphans_no_saleson_match']}")
    print(f"\nFull report written to: {out_path}")
    print("(showing up to 50 examples per category in that file)")


if __name__ == "__main__":
    main()
