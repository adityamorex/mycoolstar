"""
Spike: does SalesOn's API actually require filters as a JSON body on GET
(as its own official Postman examples show), or does it also/instead accept
them as a query string? This determines whether the WordPress plugin can
use wp_remote_get() as-is, or needs wp_remote_request() to attach a body.

Test target: reports/product-details, filtered to one known product_id.
"""
import json

import requests
from dotenv import dotenv_values
import os

HERE = os.path.dirname(os.path.abspath(__file__))
ENV = dotenv_values(os.path.join(HERE, ".env.local"))
TOKEN = ENV["SALESON_TOKEN"]
COMPANY_ID = ENV["SALESON_COMPANY_ID"]
BASE_URL = ENV["SALESON_BASE_URL"]
HEADERS = {"Authorization": f"Bearer {TOKEN}"}

PRODUCT_ID = 616725  # STICKER PIONO AP BLACK, used throughout Phase 0


def main():
    url = BASE_URL + "reports/product-details/"

    print("=== Attempt 1: filter as JSON body on GET ===")
    r1 = requests.get(
        url, params={"company_id": COMPANY_ID}, headers=HEADERS,
        json={"selected_filters": {"product_id": PRODUCT_ID}}, timeout=30,
    )
    print("HTTP", r1.status_code, "-", r1.text[:300])

    print()
    print("=== Attempt 2: filter as a query string param ===")
    r2 = requests.get(
        url, params={"company_id": COMPANY_ID, "selected_filters": json.dumps({"product_id": PRODUCT_ID})},
        headers=HEADERS, timeout=30,
    )
    print("HTTP", r2.status_code, "-", r2.text[:300])

    print()
    print("=== Attempt 3: no filter at all (baseline, to see the unfiltered/default response) ===")
    r3 = requests.get(url, params={"company_id": COMPANY_ID}, headers=HEADERS, timeout=30)
    print("HTTP", r3.status_code, "-", r3.text[:300])

    print()
    same_1_2 = r1.text == r2.text
    print(f"Body-filter and query-filter produced {'THE SAME' if same_1_2 else 'DIFFERENT'} response.")


if __name__ == "__main__":
    main()
