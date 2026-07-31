# Phase 1 Implementation Plan — Build Order & Parallelization

Source of design: `technical-implementation-plan.md` sections 1.0–1.7. This doc is the concrete file-by-file build order, written so work can be split across parallel agents without conflicts.

## Foundation (built first, sequentially — everything else depends on this)

Files, all in `wp-content/plugins/saleson-woo-sync/`:
- `saleson-woo-sync.php` — plugin bootstrap, activation hook, requires all includes
- `includes/class-saleson-api.php` — HTTP client: Bearer token + company_id (from options), retry/backoff on 5xx, GET/POST/PATCH-via-POST/DELETE helpers, JSON and multipart-form-data support (Add Product needs multipart)
- `includes/class-saleson-logger.php` — writes to `wp_saleson_sync_logs`
- `includes/class-saleson-db.php` — `dbDelta()` schema for all 4 tables: `wp_saleson_stock_cache`, `wp_saleson_price_tiers`, `wp_saleson_product_map`, `wp_saleson_sync_logs`

Key constants every downstream file needs: `warehouse_id = 14229` (HB Akeda Dungar, the only real warehouse), `company_id = 5249`, price list IDs (`1470` DISTRIBUTORS, `1471` RETAIL CUSTOMER, `1479` DEALER, `1480` SUPERMART).

Known API gotchas the client class must encode:
- Never PATCH/POST-with-id a price-list id that might not exist — crashes with HTTP 500 instead of a clean error. Create = `POST products/price-list` (no id). Update = `POST products/price-list/{id}` + `_method: PATCH` body field.
- `Add Product` (`POST products`) requires **multipart form-data**, not JSON — the only endpoint that does.
- Full party/price-list updates are **full-record replacements** — always send the complete record back, never a partial diff.

## Parallel workstreams (independent files, safe to build concurrently once Foundation exists)

1. **Settings page** (`admin/class-saleson-settings-page.php`) — Phase 1.0: token/company_id/cron-interval fields, Test Connection button (`GET reports/rate-list?page_size=1`), read-only warehouse display.
2. **Stock + price pull sync** (`includes/class-saleson-stock-sync.php` + `includes/class-saleson-price-sync-pull.php`) — Phase 1.2: WP-Cron job, pulls rate-list + low-stock-summary + all 4 price lists, upserts caches, logs every run.
3. **Product Matcher screen** (`admin/class-saleson-matcher-page.php` + a one-time import script `includes/class-saleson-map-importer.php`) — Phase 1.3: imports the mapping spreadsheet into `wp_saleson_product_map`, renders confirm/reject UI for the 183 matches, "Create as product" on-demand action for the 715 (Option B — no auto-creation).
4. **Pricing write-back + new product creation** (`includes/class-saleson-price-writeback.php` + `includes/class-saleson-product-creator.php`) — Phase 1.4 + 1.6: admin price-editor screen pushing to SalesOn price lists; hook on WooCommerce product save that creates the SalesOn product (multipart Add Product call) + adds it to relevant price lists.

## Sequencing

Foundation → all 4 workstreams in parallel → manual integration pass (register admin menu pages in bootstrap, wire cron hook) → Phase 1.7 testing on production with guardrails.
