# Pre-Phase 1 Sign-Off Checklist

*What's left before starting the actual build. Most of Part A and all of Part D are now resolved — tomorrow's call should mostly be Part B (plugin decisions) plus a couple of loose ends below.*

## Part A — From testing SalesOn's real API (Phase 0)

**1. Which warehouse holds real, sellable stock? — RESOLVED.**
Confirmed: **HB Akeda Dungar is the one and only warehouse used for all Sales Orders** — all real stock lives there, and if an item is out of stock there, it's out of stock everywhere. We'd originally found the opposite in testing (orders against this warehouse failed for every product we tried), but re-tested directly with a real finished good (`PVC Fan Heater`) instead of the spare-part items we'd used before — it succeeded immediately. The earlier failures were specific to those 3 test items (likely manufacturing components held at the Delhi factory instead), not a real problem with the warehouse itself. `warehouse_id 14229` is now the confirmed, single value to use everywhere in the plugin — no split-stock complexity to design around.

**2. Full product mapping — a spreadsheet for the client to review, nothing purged or pre-decided.**
Per instruction: no data gets deleted or excluded on our own judgment — everything is documented transparently and the client decides. **`phase0/SalesOn-WooCommerce-Product-Mapping-Review.xlsx`**, 5 tabs:
- **Summary** — the headline numbers (898 SalesOn items, 344 WooCommerce listings, how they break down) in one place.
- **Matched Products** (183 rows) — every product our matching found in both systems, so you can spot-check that the automatic pairing is actually correct. (It isn't always — e.g. one row pairs a generic "BULLET MOTOR" note in SalesOn with a specific website product purely because the words overlapped; worth a skim, not a rubber stamp.)
- **SalesOn Items Not On Website** (715 rows) — mostly expected (spare parts/raw materials), but worth a skim in case something sellable got missed.
- **Website Products Not In SalesOn** (54 rows) — real, named products with no SalesOn counterpart at all.
- **Blank Placeholder Products** (196 rows) — the blank "Product"-named junk listings. **Client decision: ignore, no action needed.**

**3. "Packed/Processing" stage — RESOLVED.**
Decided: **skip it entirely.** "Confirmed" now means: your staff verifies payment has actually been received, then manually moves the order from Pending to Confirmed. No separate Packed/Processing stage — the tracker goes straight from Confirmed to Dispatched.

**4. Warehouse-conversion quirk — downgraded to a non-issue, no longer worth raising.**
Earlier we flagged that converting an order to an invoice always assigns it to SalesOn's "primary" warehouse regardless of what's specified. Since **HB Akeda Dungar is both the primary warehouse and the only one we'll ever use**, this quirk has no practical effect on anything we're building — it would only matter if we ever wanted a different warehouse than primary, which we don't. Still technically a bug in SalesOn, just not worth spending client time on.

## Part B — Existing WordPress plugins — RESOLVED

**You've given us free rein: use any existing plugin if it fits, install new ones if needed, no need to ask permission item-by-item.** This resolves items 6, 7, 8, 9, 11, 12, 13 below — we'll make the technical call on ATUM, Custom Order Status Manager, WP ERP, PDF Invoices & Packing Slips, B2BKing, Wholesale Prices, Shiprocket, and User Role Editor during Phase 1 based on what actually fits best, and note the decision in the technical plan rather than asking each time.

**Item 10 (Code Snippets) — audited, RESOLVED.** All 4 active snippets reviewed directly. Two are purely cosmetic (share-image, a text-cleanup fix) and don't matter here. The other two turned out to be significant:
- **Role Based Pricing** — a live, working dealer-discount system on the website (flat 23%/28%/33% off by a website-only "Dealer/Distributor/Superstockist" label). Checking this against SalesOn's real API surfaced something bigger: **your SalesOn account already has real customer groups (SUPERMART, DISTRIBUTORS, CUSTOMERS, DEALER) each with its own genuine per-product price list** — not a flat percentage, and not matching the website's numbers. **Decided: SalesOn's groups/price lists are the source of truth going forward; the website's own hardcoded pricing snippet is retired.**
- **Custom Order Status Sequence** — confirmed an "Order Packed" status still technically exists in the site's status list. **Decided: doesn't matter — order status on the website will simply reflect whatever SalesOn reports, full stop, so this falls out on its own.**

**New scope surfaced by this discussion**: you've asked for a **proper role-based permission system on the website** — different staff roles able to do different operations (confirm orders, manage customer/pricing data, etc.), with all real day-to-day operations moving to the website instead of staff using the SalesOn dashboard directly. This is bigger than the single "staff approves orders" flow originally scoped for Phase 3.

**Scope boundary — decided (2026-07-27), after mapping SalesOn's full API surface against what's practical for a sales website:**
- **In scope now**: everything already core to the plan — products/pricing, parties/customers, orders→invoices, payments, warehouses, shipments/trips — **plus SalesOn's Users & Roles** (the real role-based permission system the website will need). Users & Roles is agreed scope, but **its detailed design is deferred until you've had a dedicated discussion with the client** — not being designed yet.
- **Deferred to a later phase, not now**: Expenses, Targets, and Regions/Routes/Cities (geographic master data) — real SalesOn features, technically movable to the website, but not needed for the current build.
- **Out of scope, not worth moving to a sales website**: Attendance (staff check-in/geolocation), Production/BOM (manufacturing), Van Sales (route-based field selling), and low-churn reference data (Brands/Units/Taxes/Cess) — these are internal ERP/manufacturing/field-ops functions that don't belong on an ecommerce front end.

**Pricing sync direction — RESOLVED, confirmed GO (2026-07-27).** SalesOn's groups/price lists are the source of truth to start, and pricing will eventually be editable from the website and pushed back into SalesOn — a real two-way sync. Tested directly with a disposable test price list (created, updated, verified, deleted — no real data touched): the Postman docs describe "create" incorrectly (they show PATCHing an id that doesn't exist, which actually crashes with a 500) — **the real create endpoint is `POST products/price-list` with no id**, and updating an existing list via `POST` + `_method: PATCH` works exactly as expected, including per-product price changes. **Two-way pricing sync is technically solid — no blockers.**

**Shiprocket — decided: will be used, activation deferred until that phase.** This directly affects the Bilty/LR (transport document) gap from Phase 0, previously marked NO-GO on SalesOn's side with a manual-entry fallback. Per your instruction, we're not chasing credentials or discovery on this now — it'll be activated and investigated when we reach that stage of the build.

**B2BKing / WooCommerce Wholesale Prices — decided: leave inactive.** Per your instruction, no need to dig into their history — the plan is to keep them off and build dealer/wholesale pricing directly against SalesOn's real groups/price lists instead, which we've now confirmed handles this better than either plugin would (per-product real prices, not a generic wholesale-tier bolt-on).

*(Reference — the plugins this covers: ATUM Inventory Management, Custom Order Status Manager, WP ERP, PDF Invoices & Packing Slips for WooCommerce, B2BKing Core, WooCommerce Wholesale Prices, Shiprocket, User Role Editor — full detail on each in `phase0/website_discovery_findings.md`.)*

## Part C — Real order/customer history

**14. 169 existing customers — RESOLVED.** Treat them as already-existing SalesOn parties to be matched via the normal customer-matching process we're building in Phase 2 anyway (by mobile/email) — not a special one-time reconciliation project.

**15. Order failure/cancellation rate — out of scope, per instruction.** Not something we're pursuing further; the historical order data itself isn't a concern for this project.

## Part D — Hosting/access — fully resolved, nothing needed from the client

- **Hosting plan**: Hostinger's entry-level single-website shared plan (1 CPU core, 1GB RAM, 10GB disk, 100GB bandwidth). Only one website allowed, **no staging environment available** on this tier.
- **Cron**: no real scheduled task currently configured (pseudo-cron only) — we'll set up a proper one ourselves at deployment.
- **Deployment access**: real FTP credentials + File Manager confirmed. No SSH on this plan, not needed.
- **Backups**: automatic weekly backups already in place.
- **Admin access**: already have it.
- **Testing strategy (decided)**: test directly on production, since there's no staging and no room for a second site on this plan. Guardrails: fresh manual backup before every risky step, minimal-blast-radius rollout (admin-only new screens, sync jobs verified once before running automatically), avoid heavy test operations during real traffic hours.
- **Non-blocking hygiene flag**: Hostinger's own Site Health shows 4 known plugin vulnerabilities and 18 pending updates — pre-existing, not caused by this project, but worth the client's awareness.

---

**What's actually left**:
1. **54 "Website Products Not In SalesOn" — decided: leave as-is for now, no action.** (Blank placeholder products tab — also resolved, ignore.)
2. **B2BKing / Wholesale Prices — decided: leave inactive**, no history dig needed.
3. **Shiprocket — decided: deferred to its own build phase**, no discovery needed right now.

Everything across Parts A–D is now resolved or decided. Nothing left blocking Phase 1.
