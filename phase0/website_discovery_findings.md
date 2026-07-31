# Website/WordPress/Hosting Discovery Findings

Pulled via the WooCommerce REST API's `system_status` endpoint plus targeted order/customer/settings queries — no wp-admin login needed, everything below is from data we already had access to.

## Environment
- WordPress 7.0.2, WooCommerce 10.8.1, PHP 8.2.31, MySQL 11.8.8, server: LiteSpeed (matches Hostinger's typical stack).
- PHP limits are generous: 1GB memory, 360s max execution time, 5000 max input vars — plenty of headroom for API sync jobs.
- `wp_cron: true` (not disabled) — but this only confirms WP's pseudo-cron isn't switched off; it does NOT confirm a real system cron hits `wp-cron.php` reliably. **Needs asking**: does Hostinger have a real cron job configured, or does WP-Cron rely on site traffic to fire (which could make a 15-minute sync cadence unreliable during low-traffic hours)?
- **`WP_DEBUG` is enabled on the live production site.** Not something we caused, but worth flagging as a hygiene issue — debug mode on production can leak error details and grows debug.log unbounded.
- Theme: "Loobek" v1.2.9, not a child theme — consistent with our plugin-only, theme-untouched approach.

## Active plugins that directly overlap with planned work — need client clarification on each
- **ATUM Inventory Management for WooCommerce** — a dedicated stock plugin, active. Is the client actively using this to manage stock today? If so, does SalesOn stock sync need to write into ATUM's fields specifically, or just core WooCommerce stock (which ATUM may then read/override)?
- **Custom Order Status Manager for WooCommerce** — already handles custom order statuses as a plugin, rather than something we'd need to register via code. Should we build the 6-stage order workflow using this existing plugin's UI/API instead of custom `wc_register_order_status()` calls?
- **ERP (WP ERP by weDevs)** — active. This is the "WP ERP" seen in earlier screenshots (Dashboard, Accounting, Create Invoice, Company). Is this actively used for real accounting, or installed/unused? Could overlap with SalesOn's role as the accounting source of truth.
- **PDF Invoices & Packing Slips for WooCommerce** — active, and confirmed in real use (see order 24113 below). Generates its own "Invoice Generated" order status and its own PDF invoices, natively. **Direct conflict risk**: if our plugin also flips an order to an "Invoice Generated"-named status when SalesOn creates the real invoice, it could collide with or duplicate this plugin's own unrelated invoice-generation trigger. Needs a distinctly-named status, or an explicit decision to disable this plugin's invoicing role in favor of SalesOn's.
- **Code Snippets** — active, **audited (2026-07-27), all 4 active snippets reviewed:**
  1. **Fix Product Share Image** — cosmetic only (sets the social-share image for Yoast SEO). No conflict.
  2. **Remove x000D from Product Description** — cosmetic cleanup of a bad Excel/Word import artifact in product text. No conflict.
  3. **Role Based Pricing** — a fully working dealer/wholesale pricing system, live today: reads a `customer_type` field (Dealer/Distributor/Superstockist) off the WordPress user profile and applies a flat hardcoded discount (23%/28%/33% off) to every product. **Client decision: retire this entirely** — real per-product tiered pricing already exists on the SalesOn side (see `findings.md`, "Customer groups & tiered price lists") and will replace it.
  4. **Custom Order Status Sequence** — doesn't create statuses, just reorders their display: Pending → Order Confirmed → **Order Packed** → Invoice Generated → Dispatched → Delivered. Confirms an "Order Packed" status still exists in the live status list (contradicting the earlier assumption it could be dropped silently) and confirms the exact `wc-invoice-generated` naming collision risk already flagged below with PDF Invoices & Packing Slips. **Client decision: the website will not manage its own status transitions going forward — order status will simply reflect whatever SalesOn reports**, so "Order Packed" (no SalesOn equivalent) effectively falls out of the synced flow without needing an explicit removal step.

## Inactive but installed — worth asking about the history
- **B2BKing Core** and **WooCommerce Wholesale Prices** — both dedicated B2B/dealer-pricing plugins, both inactive. Was one of these tried and abandoned for the exact "dealer sees special pricing" requirement? If either was configured before, reactivating and finishing that setup could be much faster than building dealer logic from scratch.
- **Shiprocket** — an Indian shipping/logistics aggregator, inactive. Possibly a prior attempt at solving dispatch tracking/Bilty-LR, abandoned for an unknown reason — worth asking directly, since it's the exact problem area we found a NO-GO for on SalesOn's side.
- **User Role Editor** — inactive. Could shortcut creating a "Dealer" user role rather than registering one in code.
- **WooCommerce Stripe Gateway** — inactive, alongside active Razorpay/UPI — confirms Stripe was tried and replaced at some point.

## Real payment methods currently live
Cash on Delivery, "Pay with UPI QR Code," and Razorpay (Credit/Debit/NetBanking). The planned "Pay via Credit Limit (Dealer)" option needs to be added alongside these, not replacing any.

## Tax configuration
Prices are tax-inclusive, tax is calculated based on shipping address, and displayed itemized (CGST/SGST breakdown) — need to confirm this matches how SalesOn calculates and displays tax on its own invoices (which we've seen does show a CGST/SGST breakdown), so customers don't see mismatched totals between the website cart and the final SalesOn invoice.

## Major finding: the site has real, non-trivial order and customer history
- **169 existing customer accounts** already exist in WooCommerce.
- **132 existing orders**, spanning March 2025 to July 2026 (over a year) — including one just 2 days before this discovery, paid via real Razorpay card payment.
- Status breakdown: 90 cancelled, 25 failed, 7 processing, 6 completed, 2 on-hold, 1 pending, 1 "invoice-generated" — **only ~10% of all orders ever succeeded.**
- This directly contradicts the earlier assumption (repeated back to us by the client) that "the site is not used for ordering or any business operation." Something is happening on the live site today.
- **Resolved (client input): the 169 existing customers should be treated as already-existing SalesOn parties**, matched via the same normal customer-matching process planned for Phase 2 (by mobile/email) rather than a special one-time reconciliation project.
- **Investigated the 87% failure/cancellation rate ourselves rather than leaving it as an open mystery.** Pulled the full order list and analyzed the 119 cancelled/failed orders:
  - **82/119 (69%) used "Credit Card/Debit Card/NetBanking" (Razorpay)** — heavily overrepresented vs. its overall share of payment methods used. 24 used UPI, 12 COD.
  - **Only 4/119 are near-zero test amounts** — the rest are real-value orders (₹393–₹1179+ in the sampled set), meaning this is not primarily test/abandoned-cart noise.
  - **One order carries a genuine customer note** ("Please Dispatch only Delhivery courier service") — clear evidence of a real customer, not internal testing.
  - **Date breakdown: 52 in June 2026, 30 in July 2026, 25 in May 2026, only 12 combined across all of 2025** — 90% of all failures happened in the last 3 months. **This is a recent, ongoing problem, not old historical noise.**
  - **Conclusion: this looks like a real, current checkout/payment problem, disproportionately affecting the Razorpay (card) payment path, actively affecting real customers, predating this integration project.** Recommended the client's team investigate the Razorpay integration directly — this is a pre-existing site issue, not something for us to silently build around or absorb into Phase 1 scope.

## Hosting/access — resolved directly via hPanel + wp-admin, no client input needed

- **WP_DEBUG**: enabled, but `WP_DEBUG_DISPLAY` is disabled and it only logs to a file — less concerning than first flagged, since errors aren't shown to visitors. Log file (`wp-content/debug.log`) should still be checked/rotated periodically since it's unbounded.
- **Hosting**: confirmed Hostinger's managed WordPress product via the `hostinger-auto-updates.php` mu-plugin. ABSPATH (`/home/u882891436/domains/mycoolstar.com/public_html/`) confirms the account structure.
- **Plan tier**: Hostinger's basic single-website shared hosting plan — 1 CPU core, 1024MB RAM, 10GB disk (6.35GB used), 200,000 inodes (59.78K used), 100GB bandwidth, max 40 processes, 20 PHP workers, only 1 website/addon domain allowed. This is a resource-constrained plan — sync jobs should be mindful of not saturating the 20 PHP worker limit during peak traffic.
- **Staging**: not available on this plan tier at all (Hostinger's staging tool requires Business/Cloud tier or above). Confirmed by absence in hPanel, not just a guess.
- **Cron**: hPanel's Cron Jobs page (`Advanced → Cron Jobs`) is empty — no real system cron configured. WordPress currently relies entirely on pseudo-cron (site-traffic-triggered). **Action item for deployment, not just a finding**: create a real cron job here (`php public_html/wp-cron.php` on a schedule) rather than leaving sync reliability to chance.
- **Backups**: weekly automatic backups confirmed, stored in Singapore (site itself is hosted in the Asia/India region).
- **Deployment access**: real FTP credentials available (host `mycoolstar.com` / `ftp://145.223.17.184`, username `u882891436`, upload path `public_html`), plus File Manager confirmed available in hPanel. No SSH on this plan tier — not needed given FTP + File Manager cover deployment.
- **Site Health flags** (Hostinger's own dashboard, not caused by this project): 4 known plugin vulnerabilities, 18 pending plugin updates — pre-existing hygiene risk worth being aware of before adding our own plugin.

## Real decision this surfaced: no staging + single-website-only plan means we can't test the normal way
The original technical plan assumed testing on a staging copy before touching production. With no staging tool and only one website allowed on this hosting plan, that's not available as originally assumed. Three real alternatives, needs a client decision: (a) upgrade the Hostinger plan to unlock staging, (b) test locally on a cloned copy of the site on a dev machine, (c) test cautiously directly on production with tight guardrails. Not something to quietly decide on our own.

## Not yet checked (genuinely needs the client, not just more digging)
- Whether B2BKing/Wholesale Prices/Shiprocket were ever actually configured/used, and why they were deactivated — only the client/prior developer would know this history.
