# Phase 4 — Invoices, Dispatch & Payments Sync-back: Working Plan & Status

Living document. Started 2026-08-20, built same day off findings already confirmed during the Phase 3.5 purchase-side study.

## Goal (from master plan)
Once SalesOn generates an invoice and dispatches the order, that information flows back to the website automatically - invoice number, date, amount, and a way to see the document, visible to both staff and the customer.

## The three original unknowns - all resolved before build (see `phase3.5-product-console-plan.md`)
- **Order → Invoice link**: SOLVED. A Sales Order's `association` field returns `{"Sales Invoice": [{id, transaction_no}]}` once SalesOn generates one.
- **Invoice PDF**: SOLVED, with a realistic shape. `public_url` is a full print-ready HTML invoice (real `@media print` CSS), not a downloadable file, and there's no separate PDF endpoint. Plan: link directly rather than build server-side PDF generation for a problem that link already solves.
- **Bilty/LR transport docs**: NO STRUCTURED FIELD. Free text inside `shipping_address`. Manual fallback confirmed necessary - the honest outcome the original plan committed to reporting.
- **Payments**: `GET /payments` returns 405 (endpoint exists, POST-only) - pushing looks possible, reading back through it does not. Payment *status* (`paid`/`amount_due`) is readable per invoice, which covers the display requirement.

## Built 2026-08-20

**Extended `Saleson_Order_Status_Sync`** (not a new sync class - reuses the order-detail response it already fetches every cycle for status, so this adds only ONE extra API call per order, and only for orders that actually have an invoice):
- `maybe_sync_invoice()` reads the `association.Sales Invoice` field already in hand; if an invoice exists and hasn't been synced yet, fetches its detail and stores `_saleson_invoice_id/_no/_amount/_paid/_due/_url` as order meta, plus an order note.
- Idempotent - once synced, never re-fetches (an invoice, once generated, doesn't change).

**New `Saleson_Order_Details`** (`includes/class-saleson-order-details.php`) - the display layer, deliberately read-only towards SalesOn:
- **Staff (wp-admin order edit screen)**: a "SalesOn" meta box showing the SalesOn order reference, invoice number/amount/paid/due/link once available, and an editable **Transport / Bilty-LR details** textarea - the one field with no SalesOn equivalent, saved as plain order meta (`_saleson_bilty_lr`).
- **Customer (their own order-details page)**: hooked to `woocommerce_order_details_after_order_table` - shows the same reference/invoice/payment info, plus the Bilty/LR text once staff have entered it. Nothing here is editable by the customer.
- Nothing is written back to SalesOn from this class - it only displays what the sync already pulled in, and the Bilty/LR field is pure WordPress data with no SalesOn counterpart to conflict with.

## Verification plan
1. Deploy the 3 changed/new files (`class-saleson-order-status-sync.php`, `class-saleson-order-details.php`, `saleson-woo-sync.php`), no schema change so no deactivate/reactivate needed.
2. Find a real order already at "Invoiced" or later status (several exist live, e.g. `HBIPL-17162` → `HB/26-27/870`) and confirm on the next sync cycle: the order note appears, and the wp-admin order screen's SalesOn meta box shows invoice number/amount/paid/due/link.
3. Log in as that order's customer and confirm the same information appears on their order-details page, read-only.
4. Add Bilty/LR text on one order via the staff meta box; confirm it saves and appears on the customer's order page.
5. Confirm an order with NO invoice yet shows "No invoice yet" rather than blank/broken markup.

## Files
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-order-status-sync.php` (extended)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-order-details.php` (new)
- `wp-content/plugins/saleson-woo-sync/saleson-woo-sync.php` (wiring)
