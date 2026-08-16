# Phase 3 — Order Placement & Two-Way Status Sync: Working Plan & Status

Living document. Started 2026-08-14.

## Goal (revised 2026-08-16, supersedes original master-plan wording)
Original master plan (`client-facing-integration-plan.md`) described a website-side staff approval + credit-check step. **Superseded by direct client instruction 2026-08-16: every order placed on the website (retail and trade, no exceptions) is submitted to SalesOn automatically on placement - no manual approval screen, no credit-limit gate.** Staff continue working entirely inside SalesOn as they do today (confirming payment, changing status, dispatching). The website's only job: (1) create the order in SalesOn the moment it's placed, (2) keep the WooCommerce order status automatically mirrored to whatever SalesOn reports, on the same 15-minute cadence as stock/pricing/balance. See the full design in the approved plan (this session) for `Saleson_Order_Submitter` / `Saleson_Order_Status_Sync`.

**Key challenge (resolved):** SalesOn's exact order status stages, confirmed live - see below.

## Findings

### SalesOn's real order status stages (confirmed live, 2026-08-14)
Via screenshot of `app.saleson.co.in/transactions/sales-order` (Sales Order screen), the status dropdown shows exactly:

**Pending -> Onhold -> Confirmed -> Invoiced -> Dispatch(ed) -> Delivered**, with **Cancelled** as an exit state at any point.

SalesOn's left nav separates several distinct document types under "Sales": Estimates, Sales Orders, Sales Invoices, Delivery Challans, Sales Returns - these are likely different `transaction_type` values under one shared API, not separate endpoints (see API finding below).

### API surface (confirmed live via direct API testing, 2026-08-14)
- `GET /invoices` -> 405 Method Not Allowed (confirms the endpoint exists, wrong verb)
- `POST /invoices` with an empty body -> `{"error":"Transaction type invalid"}` - confirms a required `transaction_type` field
- Tried a range of guesses (string labels like "Sale"/"Purchase", numeric codes 1-11) - all rejected. The value is very likely an internal SalesOn-specific code, not a plain string/small int, given it lines up with the multiple distinct document types (Sales Order, Sales Invoice, Delivery Challan, Sales Return, Estimate) visible in the UI.
- No public API docs discoverable at standard paths (`/api/docs`, `/swagger.json`, etc.) - those all just serve SalesOn's own frontend app shell.

### Real "Create Sales Order" request captured live (2026-08-14, via browser DevTools on a test order)
Captured the actual form fields submitted by SalesOn's own "Create Sales Order" screen (`app.saleson.co.in/create-transaction/sales-order`):

- **The field is `type`, not `transaction_type`** - this is why every earlier guess against `POST /invoices` failed; `type` itself was never being sent, so the "Transaction type invalid" error fired regardless of what value was tried.
- **Value: the literal string `"Sales Order"`** (matches the UI label exactly, not a numeric code).
- **Payload uses bracket-notation form fields** (`products[0][product_id]`, `party[party_id]`, etc.) - classic multipart/form-data or x-www-form-urlencoded style, NOT a JSON body. The plugin already has `Saleson_API::request_multipart()` built for exactly this style (used today for the "Add Product" endpoint) - reusable here.
- Full field list captured:
  - `type` = "Sales Order", `transaction_no` (blank - likely auto-generated server-side)
  - Per line item (`products[N][...]`): `product_id`, `sp_with_gst`, `name`, `hsn`, `unit`, `multiplier`, `gst`, `cess`, `mrp`, `sell_price`, `units[0][pri_unit/sec_unit/multiplier/sell_price]`, `quantity`, `committed_qty`, `free_qty`, `replace`, `discount[type/value/amount]`, `stock`, `batches` - i.e. a full snapshot of the product's data at order time, not just a bare reference (so later master-price changes don't retroactively alter existing orders)
  - `party[party_id]`, `party[name]`, `party[address]`, `party[mobile]`, `party[gstin]` - sent together even for an existing party (denormalized copy, not just an id reference)
  - Order-level: `created_at`, `grandTotal`, `receivedAmount`, `balanceAmount`, `discount`, `comment`, `creditApplied`
**Endpoint confirmed (2026-08-14): `POST https://app.saleson.co.in/api/v1/transactions/sales-order`**
- Auth: same Bearer token already used everywhere else in this integration (`SALESON_TOKEN`) - no new credentials needed.
- Content-Type: `multipart/form-data` - matches `Saleson_API::request_multipart()`, already built and used for the "Add Product" endpoint. That helper (or a close variant handling nested bracket-notation arrays for line items) can likely be reused/extended for this call.
- Response: `200 OK` confirmed live - the client's test submission via the real UI actually created a live Sales Order in SalesOn (not a dry-run/preview). **Client should cancel/delete that test order** since it's now sitting in their real order list and totals.
- This is the core technical unblock for Phase 3 - the website's "Approve" action can now be built to call this exact endpoint.

### Read-by-id endpoint confirmed (2026-08-16)
`GET https://app.saleson.co.in/api/v1/transactions/sales-order/{id}` - confirmed live via direct API call (matches what the client separately captured from the dashboard - same shape). Returns:
```json
{
  "status": "Success",
  "invoice": {
    "id": 4400705,
    "party_id": 1923537,
    "type": "Sales Order",
    "status": "Pending",
    "transaction_no": "HBIPL-17133",
    "order_no": "HBIPL-17133",
    "public_url": "https://app.saleson.co.in/c/5249/t/6A815ADB652F91",
    "transaction_products": [ { "product_id": 664765, "name": "TEST1", "quantity": 1, "sell_price": 5, "...": "..." } ]
  },
  "status_list": [
    {"id":"Pending","name":"Pending"}, {"id":"Onhold","name":"Onhold"}, {"id":"Confirmed","name":"Confirmed"},
    {"id":"Invoiced","name":"Invoiced"}, {"id":"Dispatched","name":"Dispatched"}, {"id":"Delivered","name":"Delivered"},
    {"id":"Cancelled","name":"Cancelled"}
  ]
}
```
- Same endpoint path handles both create (POST) and read (GET by id). `invoice.id` (numeric) is what to store on the WooCommerce order for later lookups; `invoice.transaction_no` (e.g. "HBIPL-17133") is the human-readable reference for staff.
- `status_list` confirms the canonical 7 values exactly - note it's **"Dispatched"**, not "Dispatch".
- SalesOn's response wraps the record under the key `"invoice"` even though `type` is `"Sales Order"` - confirms the create-order endpoint and the earlier-probed `/invoices` endpoint are the same underlying data model, reached via a type-specific path.

### Party creation - deferred (2026-08-16 client decision)
"We will look into new party creation later." Order submission therefore only works for customers who already have a `wp_saleson_party_map` row linking them to a real SalesOn party (Phase 2 accounts, or the 2 pre-existing matches). Orders from guests or any account without an existing party link are **not submitted** - the order gets a clear note explaining why, for staff visibility, rather than guessing at an unconfirmed create-party payload. Revisit once that endpoint is confirmed.

### Status: ready to build
Every blocking gap for "submit known-party orders + sync status back" is resolved. Party auto-creation for unknown customers is deliberately deferred, not a build blocker.

## Built 2026-08-16, not yet deployed live
- `Saleson_Order_Submitter` (`includes/class-saleson-order-submitter.php`) - hooked to `woocommerce_checkout_order_processed`, fires once per placed order. Resolves the customer's `saleson_party_id` via `wp_saleson_party_map` (skips with an explanatory order note if none exists - party auto-creation deferred), resolves each line item's `saleson_product_id` via `wp_saleson_product_map` (fails loudly with an order note if any item is unmapped, never submits a partial order), fetches each product's live `hsn`/`gst`/`cess`/`mrp`/`unit`/`stock` from SalesOn, builds the exact bracket-notation multipart payload captured earlier, and calls `Saleson_API::request_multipart('POST', 'transactions/sales-order', ...)`. Stores `_saleson_transaction_id`/`_saleson_transaction_no`/`_saleson_submitted_at` order meta on success, adds a visible order note either way.
- `Saleson_Order_Status_Sync` (`includes/class-saleson-order-status-sync.php`) - runs every 15 minutes from `Saleson_Stock_Sync::run()`. For every order with a `_saleson_transaction_id`, calls `GET transactions/sales-order/{id}` and maps the returned `invoice.status` onto the WooCommerce order status via a fixed table (Pending/Onhold/Confirmed/Invoiced/Dispatched/Delivered -> matching `saleson-*` custom statuses, Cancelled -> WooCommerce's native `cancelled`). Only writes when the status actually changed.
- Known simplification, not solved here: tax reconciliation between WooCommerce's tax-inclusive pricing and SalesOn's own GST calculation from the product's `gst` field - flagged in the code, follow-up item, not a build blocker.

## Manual setup - done 2026-08-16
1. ~~Remove/deactivate the "PDF Invoices & Packing Slips" plugin~~ - done.
2. ~~Register 6 new custom order statuses~~ - done, via "Custom Order Status Manager" -> "Add New Order Status" (editing the pre-existing 6 confusing ones turned out not to work - their Quick Edit "Slug" field is just the WP post's permalink slug, not the actual status key; the real key lives in a separate "Order Status Options" meta box field which was locked/uneditable on existing entries, so 6 fresh ones were created instead, old ones left unused/harmless). **Live slugs confirmed via the WooCommerce API** (`OPTIONS /wc/v3/orders` schema enum): `saleson-pending`, `saleson-onhold`, `saleson-confirmed`, `saleson-invoiced`, `saleson-dispatche` (17-char slug limit truncated "dispatched" - matches code), `saleson-delivered`. `class-saleson-order-status-sync.php`'s `STATUS_MAP` updated to match exactly.
3. Files uploaded: `class-saleson-order-submitter.php`, `saleson-woo-sync.php`, `class-saleson-order-status-sync.php` (this last one was needed immediately once `saleson-woo-sync.php` referenced it, or the site fatal-errors - happened once live 2026-08-16, fixed by uploading it).

**Still to upload**: `class-saleson-stock-sync.php` (adds the `Saleson_Order_Status_Sync::run()` call to the 15-minute cron - not yet wired in live, needed before the status-sync actually runs).

## Bug found and fixed during live verification (2026-08-16)
**The CREATE response shape differs from the READ (GET-by-id) response shape** - a wrong assumption in the original code. Create returns `{"message": "...", "id": 4401017}` (flat, no wrapper). Read-by-id returns `{"status": "Success", "invoice": {...}}` (wrapped). `Saleson_Order_Submitter` originally checked `data.invoice.id` (the read shape) on the create response, so every successful submission was misread as a failure ("Unknown error" order note), even though SalesOn had actually created the order correctly. **Fixed**: now checks `data.id` directly on the create response, then makes one follow-up GET to fetch the human-readable `transaction_no` for the order note/meta (not fatal if that second call fails - the numeric id alone is enough for status-sync to keep working).

**Side effect of the bug, cleaned up manually**: two throwaway test Sales Orders were created in SalesOn during this debugging - `HBIPL-17134` (id 4401014, the real result of WooCommerce test order #24617's actual submission, which the bug then mis-logged as failed) and `HBIPL-17135` (id 4401017, created by a manual API replicate-test to diagnose the bug). Both need to be cancelled/deleted in SalesOn as cleanup - neither represents a real customer order.

## Verified live 2026-08-16: order submission works end-to-end
Fix uploaded, both junk test orders cleaned up in SalesOn, fresh test order (#24618) placed - correctly submitted as `HBIPL-17136`, order note confirms the code's own follow-up GET verification succeeded. **Milestone 1 (submit-on-placement) is done.**

Related gap surfaced during this test, tracked separately for later (not a Phase 3 blocker): products created fresh via the website (`is_curated = 0`) don't get automatic stock/price mirror-back from either the 15-minute cron or the manual SalesOn Stock/Pricing admin tools - both are scoped to `is_curated = 1` (the original curated 220). Had to manually set the test product's WooCommerce stock by hand to get past an "Insufficient Stock" rejection during testing. Noted in memory for when "new product creation, sync properly everywhere" is picked up as its own piece of work.

## Next: verify the status-sync direction
1. In SalesOn, manually advance `HBIPL-17136`'s status (e.g. Pending -> Confirmed).
2. Wait for the next 15-minute cycle, or trigger "Run Sync Now".
3. Confirm WooCommerce order #24618 updates to the matching `saleson-*` status.

## Files/artifacts for this phase
- `phase0/phase3-orders-plan.md` - this file
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-order-submitter.php` (new, in progress)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-order-status-sync.php` (new, in progress)
