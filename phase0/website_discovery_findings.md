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
- **Code Snippets** — active. Means custom PHP code may already be running on the site via snippets, outside of any plugin we can inspect via the API. Needs a full audit before we can be confident nothing conflicts with checkout/order hooks.

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
- This directly contradicts the earlier assumption (repeated back to us by the client) that "the site is not used for ordering or any business operation." Something is happening on the live site today — whether real customer attempts, internal testing, or some mix — that needs to be understood before Phase 1, since it directly affects:
  1. Whether the 169 existing customers need to be matched/reconciled against SalesOn parties (the same kind of matching problem we already solved for products, but for people).
  2. Whether any of the 13 successful historical orders need to be reflected in SalesOn at all, or are considered out of scope/pre-integration.
  3. Why the failure/cancellation rate is so high (87%) — a payment gateway issue, checkout UX issue, or just abandoned test carts — worth understanding before building on top of the current checkout flow.

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
- Full audit of active Code Snippets content — needs someone with admin access to open the plugin and share what's actually in each snippet.
- Whether B2BKing/Wholesale Prices/Shiprocket were ever actually configured/used, and why they were deactivated — only the client/prior developer would know this history.
- Whether B2BKing/Wholesale Prices/Shiprocket were ever actually configured/used, and why they were deactivated.
