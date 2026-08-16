# Phase 2 — Customer/Dealer Accounts: Working Plan & Status

Living document. Update as decisions are made and steps completed. Started 2026-08-05.

## Goal (from original project plan)
Dealers/customers can log in on the website and see their SalesOn account info (credit limit, balance). Today: plain customer login only, no dealer concept, no credit display.

## Build order
1. Reconciliation (map SalesOn parties <-> WordPress users) — **built**, not yet run live
2. Decide the open questions below
3. Build the actual account-creation flow (batched, not one-by-one)
4. Build classification + credit data sync onto matched/created accounts
5. Resolve login access for placeholder-email accounts
6. Build recurring credit/balance sync (cron, same pattern as pricing/stock)
7. (Later, separate from this phase) actual dealer-facing balance display UI

## Status of each step

### Step 1 — Reconciliation tooling
- `wp_saleson_party_map` table added to `class-saleson-db.php`
- `Saleson_Party_Importer` (`includes/class-saleson-party-importer.php`) — pulls all parties, excludes Suppliers/TEST group, auto-matches existing users by mobile then email, previews placeholder emails. Never creates an account itself.
- **"SalesOn Parties" admin screen** (`admin/class-saleson-party-matcher-page.php`) — Matched/Unmatched/Excluded tabs, summary box
- Wired into `saleson-woo-sync.php`
- **Status: deployed, reactivated, and run live 2026-08-05 - confirmed exact match to the independent JSON analysis: 2 matched, 2368 unmatched, 395 excluded (157 supplier, 237 cancelled, 1 dummy).**
- Additionally: full reconciliation done independently via direct API analysis (not waiting on the plugin) - see `saleson_parties_map.json` (repo root), regenerable on request. Also produced `phase0/SalesOn-Parties-Reconciliation.xlsx` (superseded by the JSON per client preference, kept for reference).

### Ground truth (confirmed 2026-08-05, live pull)
- Total SalesOn parties: 2765
- Real customer parties: 2604 (type=Customer)
- Suppliers (excluded): 157
- **New finding**: 238 cancelled/test junk records identifiable by name pattern (237 named "CANCEL...", 1 "Dummy client") - excluded, same treatment as Suppliers. This was found while building the reconciliation JSON; two of these junk records were initially causing false-positive matches to real WooCommerce accounts via a shared email before the fix.
- After exclusions: 2 matched to existing WordPress users (by mobile/email), 2368 unmatched (need account creation)
- Unmatched by group: Dealer 2164, Distributor 107, Supermart 36, Customers 5, no group 56
- 25 of the 27 currently-classified WooCommerce accounts have no SalesOn backing at all (self-declared/unverified) - **decision: leave as-is for now**, revisit later

### Decisions locked in
1. Missing email -> placeholder `party-{id}@placeholder.mycoolstar.com`
2. "Superstockist" (website) = "SUPERMART" (SalesOn) - same tier, confirmed
3. Exclude Suppliers (157) and cancelled/test records (238) from this phase
4. Leave the 25 unverified existing accounts untouched for now
5. Maintain the party reconciliation as a JSON file (`saleson_parties_map.json`, repo root), not Excel, updated on request from fresh SalesOn + WooCommerce pulls

### Decision 2026-08-10: login mechanism finalized (supersedes 2026-08-05 OTP decision)
- **Username = mobile number.**
- **No OTP, no SMS gateway, no DLT registration needed** - dropped in favor of a simpler, zero-infrastructure approach.
- **Dummy password generated per account** at creation time: a unique combination of the party's mobile number and name (e.g. first 4 letters of name + last 4 digits of mobile). Generated automatically during batch import.
- **Plaintext dummy password stored and visible in the admin panel** (new column on `wp_saleson_party_map`, e.g. `generated_password`) so MyCoolStar staff can look it up any time. The actual WP login still uses the normal hashed password in `wp_users` under the hood; the plaintext copy is a staff-facing convenience field only.
- **No self-service "forgot password" flow for now.** The native WP reset-via-email is disabled/not exposed, since most accounts only have placeholder emails and the link would go nowhere. If a customer needs their password, they contact MyCoolStar and staff looks it up (or resets and re-shares) from the admin panel.
- Rejected alternatives: SMS OTP self-service reset (adds SMS gateway + DLT registration cost/timeline for no real benefit given staff is willing to handle lookups manually); WhatsApp OTP (same registration/infrastructure burden, doesn't remove the need for a fallback); staff-called-individually at creation time (unnecessary now that password lookup, not password delivery, is the only manual step).
- Not yet built: the account-creation flow itself (batched, generates username/password per party, writes `generated_password` + creates the WP user), and the admin-panel display of `generated_password` on the SalesOn Parties screen.

### Open questions - need client input (see `phase2-parties-client-discussion.md`)
1. **52 "no group" parties already have live accounts with blank `customer_type`** - need a tier decision now, more urgent than before since the accounts already exist and can log in (was: "what are the 56 no-group parties, include and at what tier")
2. Revisit the 158 excluded Suppliers before Phase 2 fully closes - do they need any handling at all?
3. The ~90-140 parties with no mobile and no email in SalesOn at all - permanently excluded unless SalesOn data is corrected, or client accepts leaving them out
4. ~~Batching order/approach for creating accounts~~ - moot, client ran it across all groups at once 2026-08-14

### Phase 2 status summary (2026-08-14)
All build steps (1, 3, 6) are done and verified live. Step 4 (classification/credit data) folded into steps 3+6 rather than being separate. Step 5 (login access) resolved by the no-OTP password design. Only step 7 (dealer-facing balance UI) remains unbuilt, plus the 3 open decisions above - Phase 2 is otherwise functionally complete.

### Real bug found and fixed along the way (independent of the parties work, but discovered while investigating customer classification)
**Pricing-by-customer_type bug**: the site's existing code (`mcs_role_price_html` and related functions, in a Code Snippet) applied a flat hardcoded discount (Dealer -23%, Distributor -28%, Superstockist -33% off Retail) instead of using the real SalesOn per-product tier prices already being synced into `wp_saleson_price_tiers`. Measured impact: averaged 7.5-8.5% overcharge across ~90% of products, with some products off by 25-30%. **Fixed**: rewrote the snippet to look up the real tier price via `wp_saleson_product_map` + `wp_saleson_price_tiers` first, falling back to the base price only if no tier price exists (also fixed a related gap where wholesale-only items with no Retail price were blocking tier pricing for everyone, including real Dealers who should see their own price). **Verified live**: test Distributor account showed the exact real SalesOn Distributor price (₹195 and ₹806 spot-checked against two different products).

### Step 3 — Account-creation flow: built 2026-08-10, not yet run live
- `wp_saleson_party_map` extended: `billing_address` (raw free-text from SalesOn, confirmed live it's a single string, not structured fields) and `generated_password` (plaintext, staff-visible) columns added to `class-saleson-db.php`. **Requires the usual deactivate/reactivate to apply via dbDelta before use.**
- `Saleson_Party_Importer` now also captures `billing_address` on every import run.
- New `Saleson_Party_Account_Creator` (`includes/class-saleson-party-account-creator.php`) — `create_batch( $limit = 300, $group_filter = null )`:
  - Selects up to `$limit` still-`unmatched` rows (optionally filtered to one SalesOn group), creates a real `customer`-role WP user per row: `user_login` = normalized mobile digits, `user_email` = the row's existing (real or placeholder) login email, password = deterministic `{first 4 letters of name}{last 4 digits of mobile}` (e.g. `Raje3210`).
  - Writes usermeta: `mobile_number`, `customer_type`, `firm_name` (party name), `gst_number`, `complete_address` (raw billing_address string).
  - On username/email collision or an unusable mobile, skips and marks the row `excluded` with a specific reason (`username_collision` / `no_usable_mobile` / `wp_insert_user_failed`) rather than overwriting anything.
  - Suppresses the default new-account WP email (`remove_action( 'user_register', 'wp_send_new_user_notifications' )`).
  - On success: writes `woo_user_id`, `generated_password`, sets `mapping_status = 'created'` (new status, distinct from `matched` = linked to a pre-existing account), `mapped_at`.
- "SalesOn Parties" admin screen gets: a "Create next 300 accounts" button (optional group dropdown), a new **Created** tab showing the plaintext password per account, and updated summary counts.
- **Deliberately batched, not all 2368 at once** - avoids PHP execution-time risk from one giant request; button is safe to click repeatedly since it only ever touches rows still `unmatched`.

**Status: run live 2026-08-14, essentially complete.** Client ran the batch across all groups (not filtered one-by-one as originally planned). Verified independently via a fresh SalesOn pull cross-referenced against live WooCommerce customers, and confirmed exact match against the admin screen's own numbers:
- **2767 parties total** - 2 matched to pre-existing accounts, **2229 new accounts created**, 0 left unprocessed, 536 excluded.
- Excluded breakdown: 158 suppliers, 237 cancelled/voided records, 1 dummy record, and **~140 account-creation exclusions** (no usable mobile+email, duplicate-mobile collisions, or creation failures) - this last bucket existed in the data all along but wasn't shown in the admin summary; fixed same day (see admin-screen update below).
- **Flag for follow-up**: since the batch was run with no group filter, it also created accounts for **52 of the 58 "no group" parties** - those accounts are live and can log in, but have a blank `customer_type` (tier still undecided), so they won't get tier pricing until that's resolved.
- The ~90-140 excluded-from-creation parties (no mobile and no email in SalesOn at all) are a genuine SalesOn data gap, not a bug - nothing to build here, needs either real contact data added in SalesOn or a client decision to permanently exclude them.

**Admin-screen fix, same day**: `class-saleson-party-matcher-page.php` summary box updated to break out `no_usable_mobile` / `username_collision` / `wp_insert_user_failed` counts explicitly (previously silently folded into the total "excluded" number with no explanation).

### Step 6 — Recurring credit/balance sync: built AND verified live 2026-08-14
- New `Saleson_Party_Balance_Sync` (`includes/class-saleson-party-balance-sync.php`) - refreshes `credit_limit`/`credit_period`/`amount_balance` on every existing `wp_saleson_party_map` row from a fresh SalesOn parties pull, then mirrors those values onto usermeta (`credit_limit`, `credit_period`, `amount_balance`, `balance_last_synced_at`) for every party with a live WP account.
- Runs on the **same 15-minute cadence as stock/pricing** - called directly from `Saleson_Stock_Sync::run()` right after `Saleson_Price_Sync_Pull::pull_all_tiers()`, not a separate cron hook. Logged as its own `party_balance_sync` row in `wp_saleson_sync_logs`.
- Deliberately refresh-only: never creates, matches, or excludes a party - that stays `Saleson_Party_Importer`'s job. A brand-new SalesOn party not yet in the map is skipped until the next manual party import.
- **Verified live 2026-08-14**: deployed, triggered via "Run Sync Now", confirmed via the WooCommerce API that `balance_last_synced_at` updated on multiple test accounts, and spot-checked a real non-zero value (dealer "Naina kathat", `credit_limit: 100.00`) matched exactly between SalesOn and the resulting WordPress usermeta. Step 6 is done.

### End-to-end pricing pipeline verification (2026-08-14)
Separately from the parties work: ran a live test to confirm the full SalesOn -> website pricing pipeline for previously-flagged "missing Retail price" products (see `phase1-key-findings-and-decisions.md` for the ~16 wholesale-only curated items). Added a temporary test Retail price (₹2599) to `BLDC WALL FAN 16 INCH 3 LEAF` (SalesOn #546234) directly via the SalesOn pricing API, confirmed it appeared on the live website (`regular_price: 2599.00`) immediately after the next sync cycle, then removed the test value. **Confirms the sync pipeline itself has no bug** - every product still showing no price for retail customers is a genuine "no Retail Customer price entered in SalesOn" data gap, not a website/sync issue. Checked 16 client-reported products this way; only 2 (EXCEL 4"/8" ROUND) have no price in *any* tier (already-known, already-decided N/A case from Phase 1); the other 14 need a Retail price added in SalesOn.

### Not yet built
- Dealer-facing balance display UI (Phase 2, step 7, separate scope) - the usermeta this cron writes (`credit_limit`, `amount_balance`) is ready for it whenever that UI gets built

### Explicitly deferred (client decision, 2026-08-14)
- Tooling/automation for **new product addition** and **new party/dealer addition** - both are currently manual (documented step-by-step this session), and building dedicated onboarding tools for either is deferred to a later phase. Not part of current scope.

### Obsolete (superseded by 2026-08-10 decision, kept for reference)
- `phase0/phase2-dlt-registration-action-item.md` - no longer needed, no SMS/OTP in this design

## Files/artifacts for this phase
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-db.php` (schema)
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-party-importer.php`
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-party-account-creator.php`
- `wp-content/plugins/saleson-woo-sync/includes/class-saleson-party-balance-sync.php`
- `wp-content/plugins/saleson-woo-sync/admin/class-saleson-party-matcher-page.php`
- `wp-content/plugins/saleson-woo-sync/saleson-woo-sync.php`
- `saleson_parties_map.json` (repo root) - full 2765-party reconciliation, regenerate on request
- `phase0/phase2-parties-client-discussion.md` - client-facing options brief
- `phase0/phase2-parties-plan.md` - this file
