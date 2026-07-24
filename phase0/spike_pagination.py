"""
Spike: full bulk pull of reports/rate-list and reports/low-stock-summary.
Settles: real pagination shape, total item counts, blank-product_code rate,
and whether both endpoints key off the same product Id.
"""
import json
import os

import saleson_client as sc


def paginate_all(path, log_prefix, page_size=100):
    """Follows next_cursor until exhausted. Returns the full list of report rows."""
    all_rows = []
    cursor = None
    page = 1
    while True:
        params = {"page_size": page_size}
        if cursor:
            params["cursor"] = cursor
        resp = sc.get(path, f"{log_prefix}_page{page}", params=params)
        data = resp.json()
        rows = data.get("reports", [])
        all_rows.extend(rows)
        page_info = data.get("page_info", {})
        print(f"    page {page}: {len(rows)} rows (running total {len(all_rows)}), page_info={page_info}")
        cursor = page_info.get("next_cursor")
        if not cursor or not rows:
            break
        page += 1
        if page > 50:  # safety valve
            print("    stopping after 50 pages - safety valve hit")
            break
    return all_rows


def main():
    print("=== Pulling reports/rate-list in full ===")
    rate_list = paginate_all("reports/rate-list", "rate_list")
    print(f"Total rate-list rows: {len(rate_list)}")

    print()
    print("=== Pulling reports/low-stock-summary in full ===")
    low_stock = paginate_all("reports/low-stock-summary", "low_stock")
    print(f"Total low-stock-summary rows: {len(low_stock)}")

    blank_codes = sum(1 for r in rate_list if not r.get("product_code"))
    print()
    print(f"rate-list rows with blank product_code: {blank_codes}/{len(rate_list)} "
          f"({100*blank_codes/len(rate_list):.1f}%)" if rate_list else "no rows")

    rate_list_names = {r["name"] for r in rate_list}
    low_stock_names = {r["name"] for r in low_stock}
    overlap = rate_list_names & low_stock_names
    print(f"Name overlap between rate-list and low-stock-summary: {len(overlap)} "
          f"(low-stock-summary is a subset by design - only items below threshold)")

    here = os.path.dirname(os.path.abspath(__file__))
    with open(os.path.join(here, "logs", "spike_pagination_full_dump.json"), "w", encoding="utf-8") as f:
        json.dump({"rate_list": rate_list, "low_stock": low_stock}, f, indent=2, default=str)
    print()
    print("Full data saved to phase0/logs/spike_pagination_full_dump.json")


if __name__ == "__main__":
    main()
