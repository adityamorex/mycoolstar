# Technical Implementation Plan: SalesOn ↔ WooCommerce Integration

## Context

My Cool Star sells fans, coolers, heaters and geysers, with all real business (orders, invoicing, dispatch, accounts) currently run manually inside the SalesOn ERP. There is also a WooCommerce site (`mycoolstar.com`). **Verified directly against the live site**: it already has a populated catalog (real categories — Coolers, Heater, Geyser, Motors, Pump, Bullet/Padestral Fan, etc. — ~300+ products across ~17 pages, pricing with sale prices, "Sold out" stock badges, a standard `/my-account/` login/register page, and a normal `/cart/`). **However, per the client, this site is not actually used for ordering or any business operation and has zero connection to the SalesOn backend today** — confirming the earlier docs' core point (SalesOn is the only real system of record) even though the site's catalog content is more populated than "dummy/unused" might suggest. Net effect for this project: the product/category data visible on the site cannot be assumed accurate, current, or connected to real stock — it's static content sitting on top of a standard WooCommerce install, waiting to be wired up.

What the current site does **not** have (confirmed by inspecting `/my-account/` — plain default WooCommerce login/register, no GSTIN/dealer fields at all): any B2B/dealer registration or role, any dealer-specific pricing or credit-limit display, any employee order-approval interface, any invoice/Bilty-LR display on orders, and no live connection to SalesOn stock/pricing/party data at all today — everything currently on the site is manually maintained WooCommerce content.

**Ground rule: we integrate with the site as it exists today and do not modify its design/theme/pages as a starting assumption.** Where a requirement needs a capability the current site doesn't have (dealer portal, employee approval screen, credit/invoice/Bilty display), that is logged as an explicit **gap** requiring a scope/build decision — not silently built to match the previously-drafted wireframes. The wireframes in this repo (`wireframes/*.html`) remain a useful internal reference for what such a gap-filling feature *could* look like, but are not an approved spec to build against.

The goal is two-way sync where SalesOn stays the system of record for inventory, party accounts/credit, invoicing and dispatch, and the website becomes the customer + employee order-management front end — per `user-requirements.txt`. Real Saleson credentials exist (a live Bearer token + `company_id=5249`, captured via browser DevTools from the client's logged-in session).

Companion document: [`client-facing-integration-plan.md`](client-facing-integration-plan.md) — same phases, written for the client.

## Why Phase 0 comes first (the core risk)

The existing internal docs (`conversation_summary.md`, `deep-research-report.md`, `initial-deep-research-report.md`) treat several things as settled that a close read of `saleson-api.txt` (a Postman export, not an API guide) shows are only documented, not verified:
- **No documented login/token-issuance endpoint.** Only `POST users/getOTP` (sends an OTP, returns nothing usable) and `POST companies/setup` (needs a token already) exist. The current token was obtained by hand via browser DevTools, not a formal credential flow.
- **No webhooks anywhere in the API.** Integration must be polling-based.
- **No bulk product/stock endpoint and no product CRUD.** Only `reports/rate-list` (name/code/price) and `reports/low-stock-summary` (name/code/stock) exist in bulk; `reports/product-details` is per-product only.
- **No image or category API.**
- **SKU/mapping gap confirmed in real data:** in the sample export `items-21-07.xlsx` (898 items), only 226 (25%) have a `Product Code` filled. Saleson's internal numeric `Id` is present on 100% of rows and is the only reliable mapping key.
- **Party GET, Payment write, and Bilty/LR fields are undocumented** — live credit-limit re-fetch, two-way Payment In sync, and dispatch-document sync (three things the client explicitly asked us to confirm) may not exist as usable endpoints at all.

Phase 0 exists to convert every one of these from "assumed" to "empirically confirmed," using real API calls, before any plugin code or client commitment is built on top of an assumption that turns out false.

---

## Phase 0 — Discovery & Validation Spike (1.5–2 days, throwaway scripts, no plugin code, no site changes)

Run each of these from a standalone PHP/Postman script — never inside WordPress, never touching the live production site — against `staging.saleson.co.in` for writes and `app.saleson.co.in` for reads, using the real captured token and `company_id=5249`:

| # | Spike | What it settles |
|---|---|---|
| 0.1 | Decode the captured token (check for a JWT `exp` claim); hit an authenticated endpoint repeatedly over a day | Whether the token expires — drives the entire auth/refresh design |
| 0.2 | Full-page pull of `reports/rate-list` | Real pagination shape, total item count, confirms product_code blank-rate |
| 0.3 | Full-page pull of `reports/low-stock-summary`, diff join keys against 0.2 | Confirms stock source joins to price source on the same `Id` |
| 0.4 | Call `reports/product-details` with one product_id, then try an array of ids | Confirms per-product-only limit, or finds an undocumented bulk mode |
| 0.5 | Call a filter-taking GET both via `wp_remote_get()` with query string and with a JSON body | Settles whether WordPress's native HTTP client works as-is or needs `wp_remote_request()` |
| 0.6 | Create one test party in staging with `opening_balacne` vs `opening_balance` | Confirms the real accepted field name (doc has a typo) |
| 0.7 | After 0.6, try `GET /parties/{id}`, `GET /parties`, and plausible report variants | Go/no-go: can we ever re-fetch a party's live credit balance? |
| 0.8 | Create a dummy Sales Order in staging, `GET transactions/sales-invoice/{id}`, inspect `status_list` | Confirms real status vocabulary/transitions to hard-code |
| 0.9 | `POST /trips` + `GET /trips/{id}` with a dummy shipment in staging | Go/no-go: can Trip/Shipment data serve as the Bilty/LR source? |
| 0.10 | Try `POST payments/credit` in staging; also check `GET payments/credit` after manually entering a payment in the Saleson UI | Go/no-go: two-way Payment In sync |
| 0.11 | Burst ~110 requests in under a minute | Real rate-limit/429 behavior to size the cron cadence against |

**Exit gate:** 0.1, 0.2, 0.3, 0.5 must be answered before Phase 1 starts (they determine the HTTP client and stock-cache design). File every other open item as a support ticket to Saleson in parallel — don't block on their reply, since several spikes above may self-answer it. Deliverable: a one-page findings note recording actual (not documented) behavior for each item above.

---

## Phase 1 — Foundational Plugin + Inventory & Pricing Pull Sync (revised estimate: 8–9 days, up from the original 4–5 — see timeline note at the end)

### 1.0 Plugin scaffold & connection settings (Day 1)
- Build out `wp-content/plugins/saleson-woo-sync/`: `saleson-woo-sync.php` bootstrap, activation hook running `dbDelta()` to create all Phase 1 tables (below) at once, `includes/class-saleson-api.php` (Bearer + company_id, retry/backoff on 5xx per 0.5's finding — and per the price-list spike, must never PATCH an id it isn't sure exists, since that crashes with a 500 rather than a clean error), `includes/class-saleson-logger.php`.
- `admin/class-saleson-settings-page.php`: token, company_id (default 5249), cron interval (default 15 min), a **"Test Connection" button** (`GET reports/rate-list?page_size=1`, pass/fail), and a **read-only display of the confirmed warehouse** (HB Akeda Dungar, id 14229) — not an editable dropdown, since there's only one real warehouse. No category-whitelist field — removed from settings entirely, not just hidden, per the no-whitelist decision below.
- No theme/frontend changes in this phase — admin-only surface area.

### 1.1 Database tables (Day 1)
- **`wp_saleson_stock_cache`** — saleson_product_id (PK), name, product_code, category/group_name, brand, stock, last_synced_at. Populated from `reports/low-stock-summary` + `reports/rate-list`, joined on Saleson `Id`.
- **`wp_saleson_price_tiers`** — saleson_product_id, price_list_id, group_id, group_name, rate, last_synced_at (composite key on product + price_list). New versus the original plan — added because Phase 0 confirmed real per-product tiered pricing already exists in SalesOn (Groups + Price Lists) and the client wants it as the pricing source of truth, replacing the website's old hardcoded discount snippet. Populated by pulling all 4 real price lists (`GET products/price-list/1470` DISTRIBUTORS, `1471` RETAIL CUSTOMER, `1479` DEALER, `1480` SUPERMART). RETAIL CUSTOMER doubles as the base/public price for logged-out visitors; the other three are cached now purely so Phase 2 can do a lookup by the logged-in party's group with no extra live API call.
- **`wp_saleson_product_map`** — saleson_product_id, woo_product_id (nullable), mapping_status (matched / unmatched / orphan / ignored), confidence, source, mapped_at. **Seeded directly from `phase0/SalesOn-WooCommerce-Product-Mapping-Review.xlsx`** — the 183 confirmed matches, 715 unmatched Saleson items, and 54 real Woo-only orphans import as starting rows rather than being re-discovered by the plugin. The 196 blank placeholder products import as `ignored`, per the decision to leave them alone.
- **`wp_saleson_sync_logs`** — run_started_at, run_finished_at, endpoint, items_processed, error_count, status. Every cron run and manual re-sync writes here.

### 1.2 Stock + price pull sync (Day 2–3)
- WP-Cron job (15 min default) pulls `reports/low-stock-summary` + `reports/rate-list`, joins on Saleson `Id`, upserts `wp_saleson_stock_cache`; a separate pull upserts all 4 price lists into `wp_saleson_price_tiers`.
- Stock is always read against the single confirmed warehouse (14229) — no per-warehouse branching needed anywhere in the sync logic.
- Already-matched Woo products (the 183) get stock/price updated directly from the cache on every run.
- A failed run logs the error to `wp_saleson_sync_logs` and surfaces on the settings page ("last sync: failed") rather than retrying silently forever or failing invisibly.

### 1.3 Product Matcher admin screen (Day 3–4)
Because `wp_saleson_product_map` is pre-seeded from the spreadsheet, this screen's job is narrower than matching from scratch:
- **183 matched rows** — a quick human confirm/reject pass, one click each (a handful are known low-confidence, e.g. the generic "BULLET MOTOR" pairing already flagged) — not a re-match.
- **715 unmatched Saleson items — DECIDED: Option B, on-demand only.** All 715 are fully synced in `wp_saleson_product_map`/`wp_saleson_stock_cache` (pricing/stock data present per the "no exclusions" decision), but **no WooCommerce product is created automatically for any of them.** The Matcher screen gets a per-row (and per-category-batch) **"Create as product"** action — only when staff deliberately click it does a real Draft WooCommerce product get created, pre-filled from the SalesOn data. This avoids force-listing spare parts/raw materials (windings, fittings, packing components) that were never meant to be individual storefront products, while keeping every one of the 898 fully priced/tracked internally either way.
- **Image requirement on publish — soft reminder, not a hard block.** When staff try to Publish a product created this way (or any product without a featured image), show a clear warning, but allow override — per instruction, not enforced as a hard gate.
- **54 Woo-only orphans** — left exactly as-is, no Saleson link forced onto them, matching the earlier decision to leave them for later.
- New-product creation going forward requires picking (or explicitly skipping) a Saleson mapping, so this gap can't quietly reopen.

### 1.4 Price write-back — staff-editable pricing pushed to SalesOn (Day 5)
- Per the confirmed two-way pricing spike: a simple admin screen where staff edit a product's price for a given group/tier, calling the now-confirmed `POST products/price-list/{id}` with `_method: PATCH` to push the change into the correct real SalesOn price list (never a nonexistent id — the plugin always knows create vs. update explicitly, per the 500-on-nonexistent-id gotcha).
- Genuinely new capability — nothing like it exists on the site today — but explicitly requested ("pricing should eventually be decided on the website, reflected in SalesOn too") and low-risk to build now since Phase 0 already proved the write path end-to-end (create → update → delete, tested and cleaned up).

### 1.6 Brand-new product creation — website → SalesOn (Day 6)
- Confirmed working live (created, verified, deleted): `POST products` (multipart form-data, unlike the rest of this API) creates a genuinely new SalesOn product — fields `state`, `unit`, `name`, `pp_with_gst`, `sp_with_gst`, `sell_price`, `warehouses[]` (JSON per entry: `warehouse_id`/`stock`/`rate`, always `warehouse_id: 14229`).
- Staff use the normal WooCommerce "Add Product" screen (name, description, photo, base price) exactly as they do today — no new UI needed here, just a hook on save. On publish/save, the plugin (a) creates the product in SalesOn via the above call, storing the returned `saleson_product_id` as product meta, then (b) adds it into the relevant price list(s) via the same write-back mechanism from 1.4, since product creation alone only sets one base warehouse-level price, not tiered pricing.
- Images are a WooCommerce-only concern — SalesOn has no image field, confirmed in Phase 0, so nothing is ever sent there.
- This is the third and final write direction confirmed this phase (alongside price write-back): **website can now create products in SalesOn, not just receive them.**

### 1.7 Testing & rollout (Day 7, spilling into Day 8–9 if needed)
- No staging environment exists on this hosting tier — tested directly on production, per the earlier decision, with guardrails: fresh manual backup before the cron job is turned on for the first time; settings/matcher/price-editor/new-product screens are admin-only (nothing customer-facing changes yet); first few sync runs triggered manually and checked before the cron runs unattended.
- **Exit gate**: the 183 known-matched products visibly track Saleson's real stock/price on a real cron cadence; all 4 price tiers are cached and correct for at least one spot-checked product; the Matcher screen's on-demand "create as product" action works end-to-end for at least one of the 715; a brand-new product created on the website shows up correctly in SalesOn (base price + at least one price-list tier); every run is visible in the sync log — verified directly on the live site, since there's no staging copy to check against instead.

**Timeline note, said plainly rather than left unchallenged:** the original 4–5 day estimate for this phase predates Phase 0 surfacing the price-tier sync requirement, the price write-back screen, and website-side new-product creation. With all three folded in, **8–9 days** is the more honest number for this phase now — by the end of it, the website's backend will genuinely be able to create/price/track products against SalesOn, not just display synced data.

## Scope boundary — which SalesOn modules move to the website (decided 2026-07-27)

Client wants "every operation done in SalesOn" eventually doable from the website, with role-based staff access. Mapped SalesOn's full API surface (see full endpoint list captured during the Code Snippets/pricing audit) against what's practical:
- **In scope, core to Phases 1–4**: Products/pricing (incl. Groups + Price Lists — create/update/delete all confirmed working), Parties/Customers, Orders→Invoices, Payments, Warehouses, Shipments/Trips.
- **In scope, added**: **Users & Roles** (`roles`, `users`, `users/permissions`) — this is the real role-based permission system the client wants staff to use on the website. Agreed as scope, but **design deferred until a dedicated client discussion** — do not design this ahead of that conversation.
- **Deferred, not this build**: Expenses, Targets, Regions/Routes/Cities.
- **Out of scope**: Attendance, Production/BOM, Van Sales, low-churn reference data (Brands/Units/Taxes/Cess) — internal ERP/manufacturing/field-ops functions, not a fit for a sales website.

## Phase 2 — Customer/Party Sync + Dealer Auth (4 days)

Depends on 0.6/0.7. Build `wp_saleson_customers` table, `POST /parties` on dealer registration (dedup by mobile/GSTIN), and a bulk/manual import path for pre-existing Saleson parties (no list-GET is documented — plan B is a client-provided export mapped by mobile number).

**Gap identified:** the current `/my-account/` page has no dealer/B2B concept at all (plain login/register only). Adding dealer registration fields (GSTIN, business type), a dealer role, and a credit/balance display is new functionality, not something the existing site already supports — flag as a scope item requiring client sign-off before building, showing an explicit "last synced" timestamp and manual refresh button rather than promising true real-time balance, given 0.7's likely gap.

## Phase 3 — Order Placement + Employee Approval + Push to Saleson (5 days)

Depends on 0.8. Register the custom order-status vocabulary (ORDER PENDING → ORDER CONFIRMED → ORDER PACKED/PROCESSING → INVOICE GENERATED → DISPATCHED → DELIVERED) on top of WooCommerce's existing order system.

**Gap identified:** there is currently no employee-facing order-approval interface anywhere in the site — this is entirely new admin functionality. Build a wp-admin screen listing pending orders with cached credit balance; "Approve" builds a JSON payload from order line items (via `_saleson_product_id`) + party (via `saleson_party_id`) and POSTs to `transactions/sales-invoice/bulk`, storing the returned transaction id and flipping status. Insufficient-credit orders stay pending with no Saleson call, per requirement.

## Phase 4 — Payments / Invoice / Dispatch Sync-back (4–5 days)

Depends on 0.9 and 0.10 — the two highest-uncertainty items; get an explicit go/no-go with the client before committing to full scope here.
- Invoice sync-back (should work regardless of 0.9/0.10): poll for status changes, pull `transactions/sales-invoice/export` for the PDF URL, surface invoice number/date/amount/PDF on the order (customer and employee views) — new functionality, no equivalent exists on the site today.
- Dispatch/Bilty-LR: if 0.9 is positive, extend the same poller to `GET /trips/{id}`; if negative, fall back to **manual Bilty/LR entry by staff** on the employee screen — say this explicitly to the client rather than silently dropping it.
- Payment In: if 0.10 is positive, build a two-way employee "Enter Payment" form; if negative, scope down to **read-only reflection** of payments Saleson already recorded, with entry staying manual in Saleson.

## Phase 5 — Testing, Hardening, Deployment (3–4 days)

Full regression on a Hostinger staging copy of the real live site (not a fresh install) so real product/category data is exercised. Verify rate-limit headroom against 0.11's real number. Simulate 4xx/5xx and confirm retry/backoff + sync-log + manual re-sync UI all work. Test idempotency (duplicate parties/orders/payments on re-run, double-click Approve). Rehearse the token-refresh runbook once if 0.1 found the token expires. Cut over to prod (real token, company_id 5249, prod URL), smoke-test one real low-value order end to end with the client's own staff, then hand off.

---

## Token runbook (item flagged in Phase 0/1)

Send a support ticket to Saleson now, in parallel, asking: (1) is there a documented OTP-verify-for-token endpoint, (2) does the bearer token expire and is there a refresh flow, (3) can they issue a long-lived server-to-server API key instead of a user-session token. Until answered: store the token as a plugin setting (never hard-coded), add a "test connection" button, and if 0.1 shows it expires, document a manual SOP (client logs into app.saleson.co.in, pulls `token` from Local Storage exactly as already done once, pastes into plugin settings) plus a `wp_mail` alert on any 401 so staleness is caught immediately rather than silently.

## Critical files

- `wp-content/plugins/saleson-woo-sync/saleson-woo-sync.php` — bootstrap, activation hooks, `dbDelta()` table creation (`wp_saleson_stock_cache`, `wp_saleson_customers`, `wp_saleson_orders`, `wp_saleson_payments`, `wp_saleson_sync_logs`)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-api.php` — HTTP client (auth, company_id, retry/backoff, error logging)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-stock-sync.php`, `class-saleson-party-sync.php`, `class-saleson-order-sync.php`, `class-saleson-invoice-sync.php`, `class-saleson-product-mapper.php`
- `wp-content/plugins/saleson-woo-sync/admin/class-saleson-settings-page.php`, `class-saleson-product-matcher-page.php`, `class-saleson-employee-dashboard.php`
- `wp-content/plugins/saleson-woo-sync/public/class-saleson-dealer-dashboard.php`
- Reference material only (not a build spec): `saleson-api.txt`, `items-21-07.xlsx`, `wireframes/*.html`

## Verification approach

- All write operations (party/order/trip/payment creation) happen against `staging.saleson.co.in` throughout Phases 0–4, never against the real prod ledger, until Phase 5 cutover.
- Each phase's exit gate is a manual QA pass by a human comparing the WordPress staging site (a copy of the real live site) side-by-side with the Saleson staging UI — not just asserting on API responses.
- Phase 5 ends with one real low-value production order run end-to-end with the client's staff as the final smoke test before handoff.
