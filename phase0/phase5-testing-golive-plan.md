# Phase 5 — Testing & Go-Live: Working Plan

Living document. Started 2026-08-26. Scope is what the original `client-facing-integration-plan.md` (§Phase 5) described, updated for how the system actually turned out — order flow is now auto-submit with no manual staff-approval screen, and the Phase 3.5 pivot added a Product Management console, auto-import, and party auto-creation that Phase 5 also needs to cover.

## Goal
Everything gets tested against real (but low-stakes) data end to end, failure modes are confirmed to fail safely rather than silently, and one real order goes through the full pipeline with staff before calling this live.

## What's already built and needs go-live testing, not more building

| Area | Status | Built in |
|---|---|---|
| Stock/price sync (all matched products, not just curated) | Built, spot-verified on 7 products | Phase 1 + this session's collision fix |
| Dealer/distributor/supermart accounts + tier pricing | Built, live-verified | Phase 2 |
| Order auto-submit to SalesOn on placement | Built, live-verified (create/read shape bug fixed) | Phase 3 |
| Order status sync-back | Built | Phase 3 |
| Retail party auto-creation on checkout | Built, **not yet live-tested with a real new signup** | Phase 3.5 |
| Product console (list/delist, needs-photo, new-in-SalesOn) | Built | Phase 3.5 |
| Auto-import of genuinely new SalesOn products | Built, **not yet live-tested** (no real new SalesOn product created since it shipped) | Phase 3.5 |
| Invoice/payment/Bilty-LR display | Built, **not yet live-verified at all** (see `phase4-invoices-plan.md`) — HPOS save bug just fixed | Phase 4 |
| Collision (many-to-one mapping) cleanup | Done for 35 known groups, 7/7 sampled correct | This session |

Two things are effectively still "Phase 4 verification" wearing a Phase 5 hat — invoice display and the Bilty/LR save fix — since they were never checked live. Rolling them into this checklist rather than a separate pass.

## Checklist

Ordered to go top to bottom: no-setup checks against data that already exists first, then things needing one piece of test data, then failure-injection, then the full end-to-end test, then cleanup.

1. [ ] Confirm an already-invoiced order (e.g. `HBIPL-17162`) shows invoice number/amount/paid/due in the wp-admin SalesOn meta box.
2. [ ] Confirm that same order's invoice info shows on the customer's own order-details page.
3. [ ] Confirm an order with no invoice yet renders "No invoice yet," not blank/broken markup.
4. [ ] Confirm the invoice `public_url` opens a real print-ready page.
5. [ ] Add Bilty/LR text as staff on one order; confirm it saves (re-test specifically — this was broken until the HPOS fix landed this session) and appears on the customer's page.
6. [ ] Add one throwaway product in SalesOn directly — confirm it appears as a draft under the Product console's "New in SalesOn" tab within one cron cycle, with correct name/price/stock, and is NOT visible on the storefront until listed.
7. [ ] List it from the console, confirm it's live and purchasable; delist it, confirm it disappears from the storefront but keeps receiving stock/price updates.
8. [ ] Place one order as a genuinely new retail signup (no existing SalesOn party) — confirm a real party gets created in SalesOn, mapped, and the order submits successfully.
9. [ ] Re-run the order submitter on an order that already synced — confirm no duplicate SalesOn order is created (idempotency guard via `_saleson_transaction_no` meta).
10. [ ] Re-run party creation on a customer who already has a party — confirm no duplicate SalesOn party is created.
11. [ ] Re-run product auto-import on a SalesOn product that already has a draft — confirm no duplicate WooCommerce product (existing duplicate-guard from `class-saleson-matcher-page.php`).
12. [ ] SalesOn API unreachable during order placement — confirm the order still saves on the website (as pending/unsynced) with a clear order note, not a lost order or a customer-facing 500.
13. [ ] SalesOn API unreachable during a sync cycle (stock/price/status/invoice) — confirm the cron logs the failure (`Saleson_Logger`) and retries next cycle rather than crashing the whole run.
14. [ ] Bad/partial data from SalesOn (e.g. a product with no price, a party with no credit fields) — confirm it degrades to "no price shown" / "not orderable," not a PHP fatal.
15. [ ] One real, low-value order placed by a real staff member (or the dealer test account) end to end: place → auto-submits to SalesOn → status updates as staff move it through SalesOn → invoice appears on the website once generated → mark it delivered → confirm the full lifecycle is visible on both the wp-admin order screen and the customer's own order page.
16. [ ] Delete/cancel every test product, test party, and test order created during this phase — in both WooCommerce and SalesOn — before calling go-live done.

## Open items not blocking go-live but worth flagging to the client
- Portable Geyser price discrepancy (client reference sheet shows 0, SalesOn shows real prices) — business decision, not a bug.
- Multi-warehouse stock: purchases land in the Delhi warehouse, not the primary (HB Akeda Dungar) the website reads — website stock can read 0 while inventory exists elsewhere in the group.
- 3 open Phase 2 decisions still outstanding: 52 untiered accounts, 158 supplier-type parties, ~140 contactless parties.

## Not in scope for Phase 5
Phase 6 (financial analytics) — separate effort, data foundation already confirmed available (purchase vs. sell price per line, tier pricing, credit/balances, returns, multi-warehouse stock).
