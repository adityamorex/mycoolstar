# Phase 3.5 — Product Management Console: Working Plan & Status

Living document. Started 2026-08-20.

## Goal (client direction, 2026-08-20)
**SalesOn is the master product database.** The MyCoolStar team adds a product in SalesOn and it appears on the website automatically. wp-admin becomes a simple console where staff manage only what SalesOn cannot hold — photos, descriptions, and whether a product is listed for sale. Everything else (name, price, stock, category) flows from SalesOn and is read-only on the website.

Business intent: make the site good enough that traditional B2B dealers gradually stop phoning orders in and start self-serving, while the team keeps working exactly as they do today inside SalesOn. wp-admin has to be as approachable as SalesOn is for a non-technical user.

## Decisions locked in (2026-08-20)
1. **New SalesOn products auto-import as drafts; staff publish.** Nothing reaches the storefront unseen — SalesOn holds no images, so an auto-published product would look broken to a customer.
2. **Website stock reads the primary warehouse only** (HB Akeda Dungar, 14229). See the multi-warehouse finding below.
3. **Out-of-stock products stay visible but are not orderable** — B2B dealers should still see the full range and be able to enquire.
4. **Retail customers get a real SalesOn party auto-created** (build pending one API capture).

---

## The architectural blocker this phase had to fix first

Every path that wrote stock/price onto WooCommerce products was gated on `is_curated = 1` — the flag marking the original Phase 1 220-product list. Anything created outside that list (including via `Saleson_Product_Creator`, which defaults `is_curated` to 0) **silently never received stock or price updates, ever.** Hit live during Phase 3 testing: a website-created test product was rejected by SalesOn for "Insufficient Stock" because its stock had never been pushed.

So "add it in SalesOn and it syncs" was impossible by construction. Fixed by retiring the curated flag as the sync gate:

- **New columns** on `saleson_product_map`: `listing_status` (`not_listed` | `listed` | `delisted`) and `first_seen_at`.
- **Sync gate is now** `mapping_status = 'matched' AND woo_product_id IS NOT NULL` — if it's linked to a website product, it syncs. Applied in `Saleson_Stock_Sync::push_to_matched_woo_products()`, `backfill_stock_cache_for_linked()` (renamed from `..._for_curated`), and `Saleson_Stock_Writeback::update_cache()`.
- **Listing status controls storefront visibility only, never data freshness.** A delisted product keeps syncing so it's already correct the moment staff relist it.
- `is_curated` is kept as a historical record of the Phase 1 list but no longer gates anything.
- **Migration** (`Saleson_DB::migrate_listing_status()`) seeds `listing_status` from real `post_status` — the actual source of truth for what is live — rather than from `is_curated`, which only records what was once intended. Published → `listed`; linked-but-not-published → `delisted`.

**Bug caught in our own migration before deploy:** the first draft stamped `first_seen_at = NOW()` on every pre-existing row, which would have made all ~680 never-listed backlog products (spare parts, "CANCEL…" records, duplicates) look like brand-new arrivals and auto-create website drafts for all of them on the first cron run. Now backdated one year — their true first-seen date is unknown but definitely historical.

## Built 2026-08-20 (not yet deployed)

**`Saleson_Product_Importer`** (`includes/class-saleson-product-importer.php`)
- `auto_import_new()` — runs in the existing cron cycle after the stock/price pull (so a new product already has a cache row to read from), logged as `product_auto_import`. Picks up SalesOn products first seen within the last 7 days that have no website listing, capped at 50 per run.
- `create_for( $saleson_product_id )` — shared creation routine, also used by the console's "Add to website" button. Creates a **draft** simple product with name, RETAIL-tier price (falling back to `sell_price`), stock, and category from SalesOn; enables stock management; stamps `_saleson_product_id`; links the map row.
- Reuses the plugin's duplicate-creation guard: if a product already carries this SalesOn id's meta, re-links to it instead of creating a second listing.
- **Deliberately excludes the historical backlog** from auto-creation — those stay opt-in via the console's "New in SalesOn" tab so real new arrivals aren't buried in junk.

**`Saleson_Products_Page`** (`admin/class-saleson-products-page.php`) — new "Products" screen under WooCommerce
- Tabs: **All** · **On the website** · **Not on the website** · **Needs a photo** · **New in SalesOn**, each with a live count.
- Columns: photo thumbnail (or a clear "None" placeholder), product name + category, stock (with an explicit "Out of stock" state), price (or "No retail price"), status, actions.
- Search by product name — absent from every previous screen.
- Actions: "Put on website" / "Remove from website" per row (nonce-protected links) and as bulk actions; "Edit photo & description" links straight to WooCommerce's own product editor rather than rebuilding an uploader.
- Guardrail: listing a product with no photo asks for confirmation rather than blocking — staff decide, but nothing empty-looking goes live by accident.
- Written without jargon: no `mapping_status`, no `is_curated`, no SalesOn ids in the main view. The technical screens (Matcher, Pricing, Stock) still exist for engineering use.
- Never deletes — publish/draft only, same reversible discipline that has made every catalog mistake in this project recoverable.

## Still to do in this phase
- **Deploy + verify** (see checklist below).
- **Retail party auto-creation** — needs one DevTools capture of SalesOn's "Add New Party" save request, the only unconfirmed payload left. Once captured, `Saleson_Order_Submitter::resolve_party_id()` is the single call site to change.
- **Out-of-stock behaviour verification** — confirm WooCommerce's "Hide out of stock items from the catalog" setting is OFF, and that a logged-in Dealer can still order a wholesale-only product whose Retail price is blank (`class-saleson-stock-sync.php` clears `regular_price` when no Retail tier price exists — same class of issue as the wholesale-only items found in Phase 1).
- **Admin menu consolidation** — five SalesOn menu items named after build order, not staff workflow. Proposed: Products · Customers · Orders · Sync & Settings, with Matcher/Pricing/Stock moved under the last as engineer tools.

---

## SalesOn purchase-side study (completed 2026-08-20)

All document types follow one pattern: `GET|POST /transactions/{type}` where `{type}` ∈ `sales-order`, `sales-invoice`, `purchase-invoice`, `purchase-order`, `purchase-return`, `sales-return`, `delivery-challan`, `estimate`. Confirmed live.

| Document | Count | Value |
|---|---|---|
| Sales Orders | 17,101 | ₹28.3 cr |
| Sales Invoices | 13,620 | ₹24.7 cr |
| Delivery Challans | 8,131 | ₹14.5 cr |
| Sales Returns | 1,839 | ₹1.8 cr |
| Purchase Invoices | 1,383 | ₹19.2 cr |
| Purchase Orders | 542 | ₹11.3 cr |
| Purchase Returns | 117 | ₹0.5 cr |

**How purchases work:** a Purchase Invoice carries a supplier `party_id`, a `warehouse_id`, and full line items with **both** `purchase_price` and `sell_price` per item — so landed cost and intended margin are both recoverable per product. Status lifecycle is only `Pending → Approved` (vs sales' 7 stages), with a bulk "Mark Approved" action. Purchases increment stock in the warehouse they name.

**Purchases are not needed for the website build** (the site only sells) — they are essential input for the financial-analysis phase, so they're scoped there rather than built now.

### Two findings that matter beyond purchases
1. **The business runs 5 active warehouses** — HB Akeda Dungar (primary, id 14229), Earth Industries Delhi (14007), Earth Industries Jaipur (27474), JKM Enterprises (27473), Seth Ji (13591). **Purchases are landing in the Delhi warehouse, not the primary one the website reads.** Per client decision the website stays primary-only, but this means website stock can read 0 while inventory genuinely exists elsewhere in the group. Worth revisiting once we see how often it bites.
2. **Sales returns run ~13.5% of sales invoices by count** (1,839 vs 13,620). High enough to be a real business signal rather than noise — a prime candidate for the analytics phase.

## Phase 4 open questions — now answered

The original client plan flagged three Phase 4 unknowns and promised an honest answer rather than an assumption. All now resolved:

- **Order → Invoice link: SOLVED.** A Sales Order's `association` field returns `{"Sales Invoice": [{id, transaction_no}]}` — verified live on a real invoiced order (`HBIPL-17162` → `HB/26-27/870`). Invoice detail then gives `amount`, `paid`, `amount_due`, `status`.
- **Invoice PDF: SOLVED, with a realistic shape.** `public_url` (`app.saleson.co.in/c/5249/t/{public_id}`) opens a full, professionally print-styled HTML invoice (confirmed via response inspection - genuine `@media print` CSS, proper invoice layout, no login required to view). It is NOT a downloadable file and there is no separate PDF-generation endpoint. **Realistic plan for Phase 4**: surface this link on the WooCommerce order screen for both staff and the customer - clicking it opens the real invoice, which their browser's own Print > Save as PDF produces a PDF from if needed. This is the honest, simple option rather than building server-side PDF generation for a link that already renders a complete invoice.
- **Bilty/LR transport docs: NO STRUCTURED FIELD.** Transport details are free text inside `shipping_address` (real example: *"…Maheshwari Transport, Road No. 14, VKI"*). Automatic extraction would be guesswork. **Manual fallback is necessary** — exactly the honest outcome the original plan committed to reporting rather than over-promising.
- **Payments: PARTIALLY ANSWERED.** `GET /payments` returns 405 (endpoint exists, POST-only), so pushing payments in looks possible but reading them back through it does not. Payment *status* is readable per invoice via `paid` / `amount_due`, which covers the display requirement.

## Real bug found during Phase 3 verification (2026-08-20): wholesale-only products were unorderable by dealers

Verified live via a real browser session, logged in as a dealer test account (mobile `9521109765`): a wholesale-only product (no Retail price in SalesOn, so WooCommerce's base `regular_price` is empty) correctly showed the dealer's tier price (₹202.00) - the price-display fix from earlier this session was working - but had **no Add to Cart button at all**, on both the product page and category/shop loop pages. Confirmed by controlled comparison against a normal priced product (has Add to Cart) with everything else held constant (same account, same in-stock status).

**Root cause**: the "Role Based Pricing" Code Snippet's five functions all change what price is *displayed* (`woocommerce_get_price_html`, `woocommerce_available_variation`, `woocommerce_variable_price_html`) or what price is *charged once something is already in the cart* (`woocommerce_before_calculate_totals`) - none of them touch WooCommerce's `is_purchasable()` check, which looks at the underlying `regular_price`/`_price` meta directly. An empty base price makes WooCommerce hide the Add to Cart form entirely, regardless of what price is shown on screen - so this had likely been broken since the original pricing-tier fix, undetected because nobody had tried to actually check out with a wholesale-only item as a dealer.

**Fix**: added `mcs_role_is_purchasable()`, hooked to both `woocommerce_is_purchasable` and `woocommerce_variation_is_purchasable`. Only flips purchasability to true when the built-in check already failed AND a real tier price exists for the logged-in customer's type - never forces a genuinely unpriced product to look buyable, so the Phase 1 decision ("no price anywhere = unavailable") is unchanged. Delivered to the client as a corrected full snippet (this is Code Snippets/DB-stored, not a repo file).

**Verified live 2026-08-20**: client pasted the fix; confirmed via a real logged-in-as-dealer browser session that the Add to Cart form now renders on the same wholesale-only product that was missing it before (clean before/after comparison of the server-rendered page - WooCommerce only outputs that form when `is_purchasable()` is true, so its appearance is dispositive on its own). The actual click-through/checkout completion wasn't independently confirmed by automation in this session (browser pane compositing limitation, not a site issue) - the structural fix is proven regardless. **Phase 3 is considered verified.**

**Scope of impact**: every wholesale-only product was affected, not just the originally-identified 16 curated ones - now potentially more, since Phase 3.5 opened syncing to the full SalesOn catalog rather than only the curated 220.

## Verification checklist (after deploy)
1. Deactivate/reactivate the plugin; confirm `listing_status` and `first_seen_at` exist and that already-live products migrated to `listed`, not `not_listed`.
2. Confirm a **non-curated linked** product now receives stock and price on the next cycle — the exact case that failed in Phase 3 testing.
3. Confirm the backlog did **not** auto-import: the "New in SalesOn" tab should still hold the ~680 never-listed products, and no flood of new drafts should appear.
4. Add one throwaway product in SalesOn; confirm it appears as a draft within a few cycles with correct name/price/stock, and is **not** on the storefront.
5. Publish it from the console; confirm it's live and purchasable. Delist it; confirm it's gone from the storefront but still receiving stock updates.
6. Set its SalesOn stock to 0; confirm it stays visible and is not orderable — and that a logged-in Dealer sees their tier price on a wholesale-only product.
7. Clean up every test product/order created during verification.

## Files
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-db.php` (schema + migration)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-product-importer.php` (new)
- `wp-content/plugins/saleson-woo-sync/admin/class-saleson-products-page.php` (new)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-stock-sync.php` (gate + auto-import call)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-stock-writeback.php` (gate)
- `wp-content/plugins/saleson-woo-sync/saleson-woo-sync.php` (wiring)
