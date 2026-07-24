"""
Spike: does our one reusable token still authenticate? Run this again a day
(or more) from now to build real confidence in how long it lasts, rather
than relying on a single check. Appends each run's result to a small
history file so we can see the trend over time.
"""
import base64
import json
import os
from datetime import datetime, timezone

import saleson_client as sc

HERE = os.path.dirname(os.path.abspath(__file__))
HISTORY_FILE = os.path.join(HERE, "logs", "token_recheck_history.json")


def decode_token_info(token):
    try:
        raw = base64.b64decode(token).decode("utf-8", errors="replace")
    except Exception as e:
        return f"could not decode: {e}"
    return raw


def main():
    print("=== Token re-check ===")
    print("Decoded token contents:", decode_token_info(sc.TOKEN))

    resp = sc.get("dashboard/statistics", "token_recheck")
    ok = resp.status_code == 200
    print(f"Authenticated OK: {ok}")

    history = []
    if os.path.exists(HISTORY_FILE):
        with open(HISTORY_FILE, encoding="utf-8") as f:
            history = json.load(f)
    history.append({
        "checked_at": datetime.now(timezone.utc).isoformat(),
        "http_status": resp.status_code,
        "authenticated_ok": ok,
    })
    with open(HISTORY_FILE, "w", encoding="utf-8") as f:
        json.dump(history, f, indent=2)

    print()
    print(f"History now has {len(history)} check(s), saved to {HISTORY_FILE}")
    print("Re-run this script again in a few days to build a longer track record.")


if __name__ == "__main__":
    main()
