# My Cool Star Website ↔ SalesOn Integration — Project Plan

## 1. What we're building

Today, all real business — orders, stock levels, customer credit, invoicing, dispatch — is managed by hand inside SalesOn. The website (`mycoolstar.com`) has a product catalog on it, but it is not connected to SalesOn and is not used for actual ordering.

The goal of this project is to connect the two systems so that:
- Stock and pricing on the website automatically reflect what's in SalesOn.
- Customers and dealers can place orders on the website.
- Your staff can review and approve those orders before they become real SalesOn transactions.
- Once SalesOn generates an invoice and dispatches the order, that status, the invoice, and the transport (Bilty/LR) details flow back to the website automatically, so both your staff and the customer can see them.

SalesOn stays the master system for inventory, accounts, invoicing and dispatch — nothing about how your team works inside SalesOn changes. The website becomes a front door into that process.

## 2. Why this is being done in phases, and why Phase 0 comes first

This integration depends on a third-party system (SalesOn) whose technical documentation is incomplete in several important places — things like "can we get a customer's live credit balance," "can payments be pushed both ways," and "can we retrieve transport/Bilty documents" are not clearly answered in what SalesOn has published. Some of these are core to what you asked for.

Rather than promise a full feature set and discover partway through the build that a piece isn't actually supported, we're starting with a short, focused **Phase 0** whose only job is to test the real SalesOn system directly and get definite yes/no answers on the things that are currently uncertain. Everything after Phase 0 is scoped around real, confirmed answers — not hopeful assumptions.

**Important expectation to set now:** it is possible that one or two of the more ambitious asks (real-time credit balance, two-way payment sync, automatic Bilty/LR retrieval) come back from Phase 0 as "not supported by SalesOn today." If that happens, we will tell you immediately and propose a practical fallback (for example, a manual entry step for staff) rather than silently dropping the feature or over-promising.

## 3. Phase-by-phase plan

### Phase 0 — Discovery & Validation (1.5–2 days)

**Goal:** Get definitive answers to every open question about what SalesOn's API actually supports, using real test calls — before committing to build anything.

**What we'll test:**
- Whether the login/access token SalesOn issues expires, and how it would need to be renewed.
- Whether stock and pricing can be pulled in bulk on a schedule (needed for automatic stock sync).
- Whether a customer's live credit limit and outstanding balance can be re-checked after the fact (needed for the credit-check-before-approval step).
- What the real order/invoice status stages look like inside SalesOn.
- Whether transport/Bilty-LR documents can be retrieved through the API, or only exist inside the SalesOn interface.
- Whether payments entered on the website can be pushed into SalesOn, and vice versa.
- The real rate/volume limits SalesOn enforces, so we don't design something that gets throttled.

**Why this matters to you:** several of the requirements you listed (question F: two-way payment sync, question G: real-time credit visibility, and the Bilty/LR sync in point 8) depend entirely on SalesOn's API actually supporting them — which isn't confirmed yet. This phase gives you a clear, honest picture of what's actually possible before we scope and estimate the rest of the project.

**What we already have to start:** you've approved creating dedicated test customer groups and test products inside SalesOn for this purpose, so all Phase 0 testing will run against clearly-marked test data rather than your live catalog or real customer accounts. We also already have a point of contact at SalesOn (our existing POC) to route any questions that testing alone can't answer.

**Deliverable:** a short findings report answering questions A–G from your original requirements list, plus a rate-limit and token-behavior summary.

---

### Phase 1 — Live Inventory Sync (4–5 days)

**Goal:** Website stock and pricing for your existing product catalog automatically stay in sync with SalesOn.

**Key challenge:** SalesOn's product records mostly don't have a usable product code — only about 1 in 4 items in the sample export had one filled in. We'll instead map products using SalesOn's internal ID number, which every item does have. This does mean a one-time manual step: someone (from our side, with your input) needs to match each of your ~300 website products to the correct SalesOn item once. After that, new products get matched as they're added — it's a one-time setup cost, not an ongoing one.

**Another challenge:** SalesOn's product list includes both finished goods (fans, coolers, heaters, geysers) and internal spare parts/raw materials (motors, windings, fittings). We'll build a simple settings screen so you can control which categories are allowed to show up on the website, so spare parts don't accidentally appear for sale.

**What changes for you:** nothing changes in how you use SalesOn day to day. The website will start showing real, current stock and prices instead of static content.

---

### Phase 2 — Customer/Dealer Accounts (4 days)

**Goal:** Dealers can register/log in on the website and see their account information from SalesOn (credit limit, balance).

**Key challenge:** the website currently has only a plain customer login — there's no dealer concept, no GST field, no credit display at all today. This is new functionality we'll be building, not an upgrade of something that already exists.

**Depends on Phase 0 answering:** whether we can ever re-fetch a dealer's updated balance after the fact. If not, we'll show the balance as of the last sync with a clearly labeled timestamp and a manual refresh option, rather than claiming it's live when it isn't.

---

### Phase 3 — Order Placement & Staff Approval (5 days)

**Goal:** Customers/dealers place orders on the website; your staff review and approve them before they become real SalesOn transactions.

**What gets built:** an order gets a "Pending" status on the website first — no SalesOn transaction exists yet. A staff member sees it on a new approval screen (this doesn't exist today either — it's new functionality), checks the customer's credit, and clicks Approve, which is the moment the order actually becomes a transaction inside SalesOn. If credit isn't sufficient, the order simply stays pending.

**Key challenge:** confirming SalesOn's exact order status stages (from Phase 0) so the website statuses line up correctly with what's really happening in SalesOn.

---

### Phase 4 — Invoices, Dispatch & Payments Sync-back (4–5 days)

**Goal:** once SalesOn generates an invoice and dispatches the order, that information flows back to the website automatically.

**What should work:** invoice number, date, amount, and a downloadable PDF appearing on the order — for both staff and the customer.

**What's uncertain until Phase 0 confirms it:**
- Whether Bilty/LR transport documents can be pulled automatically, or need to be entered manually by staff on the website as a fallback.
- Whether payments can genuinely flow both directions, or whether payment entry needs to stay inside SalesOn with the website only displaying what's already there.

We will tell you plainly which of these landed as "fully automatic" vs. "manual fallback" once Phase 0 is done, rather than assuming the best case.

---

### Phase 5 — Testing & Go-Live (3–4 days)

**Goal:** everything is tested against a safe copy of your real website and a SalesOn test account before touching your live systems.

**What happens:** we simulate failures (SalesOn being slow or down, bad data) and confirm the system logs errors and lets staff manually retry rather than silently failing. We test that re-running things doesn't create duplicate customers, orders, or payments. Finally, we do one real, low-value order test end-to-end with your staff before calling it done.

## 4. Open questions we need SalesOn's confirmation on (in parallel with Phase 0)

These are being sent to SalesOn support directly, since some can only be answered by them, not by testing alone:
- Is there a proper login/token-issuing endpoint for integration partners, separate from a regular user login?
- Does the access token expire, and if so, how should it be renewed going forward?
- Can we get a long-lived, dedicated integration API key instead of relying on a regular user's session token?
- Can Bilty/LR transport documents be retrieved through the API?
- Can payments be pushed to SalesOn from an external system, and fetched back?
- Can a customer's live/current credit balance be retrieved after their account is created?

## 5. What we need from you to start

- **Already in place:** approved test customer groups and test products inside SalesOn for Phase 0/testing use, and an existing SalesOn point of contact for questions that testing alone can't answer.
- Sign-off on the phase order and the honest "fallback if not supported" approach described above — we'd rather under-promise and confirm than promise everything up front and walk it back later.

## 6. Timeline summary

| Phase | Focus | Estimated duration |
|---|---|---|
| 0 | Discovery & validation against real SalesOn API | 1.5–2 days |
| 1 | Live inventory/stock/price sync | 4–5 days |
| 2 | Dealer accounts & credit display | 4 days |
| 3 | Order placement & staff approval | 5 days |
| 4 | Invoice, dispatch & payment sync-back | 4–5 days |
| 5 | Testing & go-live | 3–4 days |

**Total: roughly 3–4 weeks of focused work**, with Phase 0's findings potentially adjusting the scope/estimate of Phases 2 and 4 once we know exactly what SalesOn's API can and can't do.
