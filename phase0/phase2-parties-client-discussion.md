# Phase 2 — Customer/Dealer Accounts: What's Available, What's Needed, and the Open Decisions

Prepared for client discussion — 2026-08-05

## 1. What SalesOn actually has, per customer/dealer ("party")

| Field | Availability | Notes |
|---|---|---|
| Name | Always present | |
| Mobile number | 96%+ of real customers | The one reliable identifier |
| Email | Rare — ~26 of 2604 real customers | Cannot be relied on as the primary identifier |
| GSTIN | ~20% of Dealers, ~59% of Distributors, rare elsewhere | Expected — many customers aren't GST-registered |
| Group (Dealer / Distributor / Supermart / Customers) | Present for ~92% of real customers | Maps directly to the website's existing "Customer Type" field (Supermart = "Superstockist" on the website, confirmed same tier) |
| Credit limit / credit period | Present for nearly all real customers | Real, active business data |
| Outstanding balance | Present, changes over time | Needs ongoing refresh, not a one-time pull |
| Billing/shipping address | Present for most | |

**Total real customer parties: 2604.** Additionally: 157 are Suppliers (not customers, excluded), and 238 are cancelled/test records identifiable by name pattern (237 named "CANCEL...", 1 "Dummy client") — excluded as junk data, not real accounts.

## 2. What WooCommerce/WordPress requires to create a real account

- A **unique email address** (WordPress's core requirement — doesn't have to be a working inbox, but must be unique and pass format validation).
- A **unique username**.
- A **password** — or a way for the customer to set one (normally: an email link, which doesn't work for accounts with no real email).
- A **role** (Customer) plus, on this site specifically, the custom "Customer Type" classification field that the pricing system already reads.

## 3. The core problem

**2346 of the 2604 real customer parties have no email address in SalesOn.** WordPress can't create a normal account without one. This is the single blocker everything else depends on.

## 4. Options for the client to decide

### A. How to identify accounts without a real email
- **Chosen so far**: generate a placeholder email (`party-{id}@placeholder.mycoolstar.com`) purely as an internal WordPress identifier — never used for login or communication.

### B. How customers actually log in
Two genuinely different paths:

| Option | What it requires | Trade-off |
|---|---|---|
| **Mobile number as username + staff-issued password** | No new development. Username = their real mobile number; staff sets an initial password and shares it directly (call/WhatsApp). | Works immediately. Customer can't self-serve a password reset (no real email) unless staff resets it manually. |
| **Mobile + OTP login** (no password, SMS code to log in) | New development: SMS gateway integration (e.g. MSG91/Twilio), custom login flow, ongoing per-SMS cost | Better customer experience, closer to what dealers might expect, but a real scoped feature — not a data-sync task |

**Recommendation on the table**: start with the simple option to unblock Phase 2 now, treat OTP as a possible later enhancement.

### C. How many accounts to create, and in what order
Given the scale (2368 real accounts to create), doing this one-by-one isn't practical the way individual product creation was. Options:
- Create in batches by group — Dealers (2164) first since they're the largest and most revenue-relevant, then Distributors (107), Supermart (36), Customers (5).
- Create all at once (technically needs to run in chunks regardless, to avoid timeouts, but could be presented as "one operation").

### D. The 56 parties with no group assigned at all
Unclear what tier these belong to. Need the client's input on what these actually are before deciding whether to include them, and if so, at what tier.

### E. The 157 Suppliers
Deliberately excluded from this phase — **flagged to revisit with the client before Phase 2 fully closes**, in case any need separate handling (e.g., a future supplier-facing portal), though nothing suggests that's needed yet.

### F. Existing unverified accounts on the website today
25 of the current 27 manually-classified Dealer/Distributor/Superstockist accounts have **no matching SalesOn record at all** — meaning they were classified by staff without verification. **Decision so far: leave these untouched for now**, revisit separately.

## 5. What happens after accounts exist — ongoing maintenance

Credit limit and outstanding balance change over time in SalesOn. A one-time import goes stale immediately. This needs the same kind of recurring sync already running for pricing and stock (every 15 minutes) — a separate, ongoing piece of work, not part of the initial account creation.
