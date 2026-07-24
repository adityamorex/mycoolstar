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

## Phase 1 — Foundational Plugin + Inventory Pull Sync (4–5 days)

- Build out the existing empty `wp-content/plugins/saleson-woo-sync/` scaffold: `saleson-woo-sync.php` bootstrap, `includes/class-saleson-api.php` (Bearer + company_id, retry/backoff per 0.5's finding), `includes/class-saleson-logger.php`, `admin/class-saleson-settings-page.php` (token, company_id, environment, cron interval, category whitelist). No changes to theme/frontend pages in this phase.
- New table `wp_saleson_stock_cache` (saleson_product_id PK, name, product_code, category, brand, price, stock, last_synced_at), populated by a WP-Cron job pulling `rate-list` + `low-stock-summary` and joining on Saleson `Id`.
- **Mapping key decision:** use Saleson's numeric `Id` as `_saleson_product_id` product meta on existing Woo products (always present); keep `Product Code` as a display-only `_saleson_product_code` field. Since there is no reliable auto-match against the ~300 existing live Woo products, build a one-time **Product Matcher admin screen** — unmapped Woo products vs. unmapped Saleson catalog rows, fuzzy-name-suggested, human-confirmed by click. Require this mapping picker on new-product creation going forward so the gap doesn't recur.
- **Catalog curation:** category whitelist stored in plugin settings (auto-populated from cache, finished-goods categories pre-checked, spare-part/raw-material categories unchecked by default), applied at cache→Woo sync time, with a per-product override checkbox for exceptions — needed because Saleson's catalog mixes sellable finished goods with internal spare parts/raw materials.
- Exit gate: existing live catalog's stock/price visibly track Saleson on a real cron cadence in staging, with every run logged to `wp_saleson_sync_logs`.

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
