# Pre-Phase 1 Sign-Off Checklist

*Everything we need answered in tomorrow's call before starting the actual build. Organized so we can work through it in one sitting — anything left unanswered becomes a blocker for Phase 1, not a "we'll figure it out as we go" item.*

## Part A — From testing SalesOn's real API (Phase 0)

1. **Which of your 5 active SalesOn warehouses hold real, sellable stock?** (Seth Ji Ware House, Earth Industries Delhi, HB Akeda Dungar [marked "primary" but has no real stock for anything we tested], JKM Enterprises, Earth Industries Jaipur.) If more than one, how is stock split between them?
2. **The 54 real website products with no plausible SalesOn match** — discontinued in SalesOn, listed under a very different name, or never entered into SalesOn at all? (Full list in `phase0/logs/reconciliation_v2_report.json`.)
3. **"Order Packed/Processing" stage** — SalesOn has no real signal for this. Drop it from the website's order tracker, or keep it as a manual staff-set marker with no automatic confirmation?
4. **The 196 blank placeholder products** live on the site right now (Electric Geyser category) — separate, urgent website fix, unrelated to timing of this project. Delete or unpublish?
5. **Heads up, not a question**: we found SalesOn's own invoice-conversion endpoint has a real bug — it always assigns the primary (empty) warehouse regardless of what's specified. Worth you raising directly with SalesOn support, since it may be quietly affecting their own stock ledger too.

## Part B — From discovering the actual website/WordPress setup (new, found today)

6. **ATUM Inventory Management is active** — are you using this for stock today? If so, does our stock sync need to write into ATUM specifically, or just core WooCommerce (which ATUM might then read/override)?
7. **Custom Order Status Manager is active** — should we build the order-status workflow using this plugin's own system, instead of writing custom code to register statuses?
8. **WP ERP (by weDevs) is active** — is this used for real accounting/invoicing today? Could overlap with SalesOn's role.
9. **PDF Invoices & Packing Slips for WooCommerce is active and in real use** — it already creates its own "Invoice Generated" order status and its own PDF invoices. If we also use an "Invoice Generated" label for the SalesOn-driven status, it risks colliding with this plugin. Should we rename ours, or turn this plugin's invoicing off in favor of SalesOn's?
10. **Code Snippets plugin is active** — can you (or whoever manages the site) share what custom snippets are currently running? We can't safely build around hooks we don't know exist.
11. **B2BKing Core and WooCommerce Wholesale Prices are both installed but inactive** — were either of these ever set up/tried for dealer pricing? If so, why were they turned off? Could reactivating and finishing one be faster than building dealer pricing from scratch.
12. **Shiprocket is installed but inactive** — was this ever tried for dispatch/shipment tracking? Directly relevant since we found SalesOn itself has no way to track Bilty/LR documents.
13. **User Role Editor is installed but inactive** — fine to reactivate to create a "Dealer" role, or is there a reason it's off?

## Part C — Real order/customer history that changes our starting assumptions

14. **169 customer accounts and 132 orders already exist on the site** (dating back to March 2025, most recent 2 days ago via a real card payment). This contradicts what we understood earlier — that the site wasn't being used for ordering. **What's actually happening with these orders today?** Are they real customer attempts, internal testing, or something else? Do any of the 169 customers or the 13 successful historical orders need to be matched into SalesOn, or are they out of scope?
15. Related: **87% of all orders (115/132) ended up cancelled or failed.** Is this a known issue (payment gateway problems, checkout friction), or just abandoned/test carts? Worth understanding before we build on top of the current checkout flow.

## Part D — Hosting/access — RESOLVED OURSELVES, no client input needed

16. ~~Plan type + staging~~ **Resolved:** Hostinger's basic single-website shared plan (1 CPU core, 1GB RAM, 10GB disk, 100GB bandwidth, only 1 website/addon allowed). **No staging environment is available on this tier at all.**
17. ~~Real cron vs. traffic-triggered~~ **Resolved:** No system cron currently configured — WordPress relies purely on pseudo-cron (site-traffic-triggered). We can fix this ourselves by adding a real cron job in hPanel during deployment.
18. ~~Deployment access~~ **Resolved:** Real FTP credentials available (host `mycoolstar.com`, username `u882891436`, path `public_html`), plus File Manager. No SSH on this plan tier, but not needed.
19. ~~Backup policy~~ **Resolved:** Weekly automatic backups (stored in Singapore).
20. ~~Admin access~~ **Resolved:** already have it.

### New decision this surfaced — DECIDED
**21. How do we safely test before touching the live site, given there's no staging tool and only one website is allowed on this plan? → Decided: test directly on production**, with these guardrails now baked into the plan rather than left implicit:
- Take a **fresh manual backup immediately before every risky step** (first plugin install/activation, each major update) — the weekly automatic backup alone isn't a tight enough safety net for this.
- All SalesOn-side testing keeps using clearly-labeled test data (`ZZTEST-P0-*` convention, staging on SalesOn's side is not the issue here — SalesOn already only has one real account) — this part was already how Phase 0 worked and continues unchanged.
- New plugin functionality gets rolled out with minimal blast radius where possible — e.g., feature-flag the sync jobs off until manually verified once, keep new admin screens admin-only, avoid anything that could affect a live customer checkout until it's been confirmed safe.
- Given the resource-constrained hosting plan (1 CPU core, 20 PHP workers), avoid heavy/bulk test operations during real traffic hours.

### Related hygiene flag, worth mentioning even though it's not blocking
Hostinger's own Site Health check on the site currently shows **4 known plugin vulnerabilities and 18 pending plugin updates**. Not caused by us, but worth being aware of before adding our own plugin into this mix — an outdated/vulnerable plugin elsewhere on the site is a pre-existing risk, not something this project introduces.

---

**Goal for tomorrow**: get through Parts A–C in one sitting (Part D and item 21 are already resolved/decided). Anything genuinely unanswerable on the spot (e.g. needs someone else to check something) should get an explicit owner and a deadline, so it doesn't quietly become a mid-build surprise later.
