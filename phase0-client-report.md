# Phase 0 — Discovery & Validation: Progress Report

*This is a living document — updated as each item is completed. It will be shared with you in full once Phase 0 wraps up, before we move into building any part of the actual integration.*

**Status: In progress.** Below is what we've confirmed so far, in plain language, and what's still left to check.

---

## 1. Access & login — confirmed working

We now have a single, working way to connect to your SalesOn account (company: Heaven Basket India Pvt Ltd) that doesn't require repeatedly asking you for a login code. It's been tested and is working reliably. No action needed from you here.

## 2. Customer/dealer account data — mostly good news

We tested creating a customer record, updating it, and reading it back, using clearly-labeled test entries (not real customers). Findings:

- **We can reliably fetch a customer's live credit balance after they're created** — this directly enables the "dealer sees their real credit balance on the website" feature from your requirements. ✅
- **One important safety finding**: when updating a customer's details, SalesOn's system requires re-sending the customer's *entire* record, not just the one field being changed — otherwise it silently blanks out other fields (in our test, updating just the credit limit wiped the test customer's email and mobile number). We caught this now, in a test account, specifically so it never happens to a real customer's data later. The plugin will be built to always account for this.
- **Bonus:** SalesOn provides a ready-made, shareable "account statement" web page per customer (with your company letterhead, transaction history, running balance) that we can likely link to directly from the dealer dashboard, saving us from building that screen from scratch.
- **Another safety finding**: SalesOn allows the exact same customer (same mobile number, same email) to be created twice with no warning — it won't stop us from accidentally creating duplicate customer records. We'll build the plugin to check for an existing customer by mobile/email before creating a new one, since SalesOn itself won't catch this for us.
- **Good news on deletion/cleanup**: we tested removing test customer records, including one with real linked orders and invoices — it handled this safely, keeping the order/invoice records intact and correctly linked rather than breaking anything. All of our test data has now been cleaned up from your account (or clearly documented as intentionally left behind where no delete option exists).

## 3. Product data & images — confirms our original plan was right

We checked whether SalesOn actually stores product photos for your items. Answer: **no, it doesn't** — we specifically checked a real product (a cooler) that has photos live on your website today, and SalesOn's own record for that same item has no image at all. This confirms the plan's original approach: **the website remains the only source of product photos**, and SalesOn will only ever supply stock/pricing — nothing changes here, this was simply confirmed rather than assumed.

## 4. Data discovery — product catalog comparison (the big item this update)

We pulled your **entire real product catalog from both systems** — all 898 items from SalesOn, and all 344 products currently on the website — and compared them directly. Two findings came out of this, one urgent and one reassuring:

### 4a. Urgent, separate issue found on the website itself

**196 of your 344 website products (57%) are blank placeholder listings** — literally named `"Product"`, with no price, no description, no photo. They were all created in a single batch on 2026-06-30, and all of them are currently **live and shown as in-stock** to anyone browsing the "Electric Geyser" category. This has nothing to do with the SalesOn integration — it's a data-quality issue on the website as it stands today. Per your direction, we haven't touched or deleted anything — these are documented in the product-mapping spreadsheet (Blank Placeholder Products tab) for you to review and decide on in your own time.

### 4b. The reassuring finding: your catalogs likely match up far better than they first appeared to

Once we set the 196 blank listings aside, we tried to match the real products between SalesOn and the website by name, and at first it looked alarming — almost nothing matched. Digging into *why* revealed the real explanation: **SalesOn and the website name the same products differently, in a consistent, predictable way** — for example:

| In SalesOn | On the website |
|---|---|
| `16 INCH ORA TOWER` | `MCS 16" ORA Tower Cooler` |
| `16 INCH COMMERCIAL BODY` | `MCS 16" Commercial Cooler` |
| `16 INCH WINDSOR` | `MCS 16" Windsor Cooler` |

The website consistently adds your `MCS` brand name and a descriptive word (Cooler/Fan/Heater), while SalesOn's internal names are shorter and more like inventory codes. These are clearly the same physical products — a simple name-matching script just can't see through the difference automatically.

**What this means practically:** as planned from the start, matching each SalesOn item to its correct website product will need a short one-time human review (we'll build a simple side-by-side screen for this), rather than something that can be done invisibly by a computer. This was already the plan — this exercise just gave us solid, concrete proof of *why* that's the right call, instead of just assuming it.

**One more useful thing we learned:** your SalesOn catalog is organized into ~42 categories, and it turns out you sell a much wider range of appliances through SalesOn than just fans/coolers/heaters/geysers — items like irons, kettles, mixers, induction cooktops, washing machines, and more all show up. **Resolved**: we checked whether spare-parts-sounding categories should be excluded and found no real evidence to justify holding any of them back — some (like motors) are already genuinely sold on your site today. Per your direction: we're syncing your entire SalesOn catalog, all ~898 items, with no categories excluded.

### 4c. Final numbers, after teaching the matching to see through the naming difference

Once we accounted for the brand-prefix/wording pattern above, the real match count jumped from 29 up to **183 products** — confirming most of the apparent "gap" really was just a naming difference, not missing products. That leaves two genuinely useful, concrete numbers for planning:

- **54 real website products have no plausible match in SalesOn at all.** This is the one number from this whole exercise that represents an actual, specific gap rather than a naming quirk — a mix of cooler models (RIO, TIGER, LOTUS, Columbus, Rambo, Shine), several ceiling fan variants, madhani/churner products, a handful of cooler motors, and a couple of induction cooktops. We'll need your input on each: are these discontinued in SalesOn, listed there under a very different name, or were they only ever added to the website and never entered into SalesOn?
- A meaningful chunk of SalesOn's fan/cooler/heater/AC items still don't have an obvious website match even with the improved comparison — this will get a closer manual look during the one-time matching step in the next phase, rather than us guessing now.
- One honest caveat: the improved matching isn't perfect either — about 7% of its matches come from very short SalesOn names (like just `"KETTLE"` or `"CEILING FAN"`) that could, in principle, match the wrong specific model. This is exactly why we're building a human-confirmed matching screen rather than trusting any algorithm to do this silently — this exercise gave us solid proof that's the right call, not just a cautious assumption.

## 5. Warehouse question — resolved

Your SalesOn account has 5 active warehouses. Our first round of testing (using a few spare-part items) made it look like the "primary" warehouse, HB Akeda Dungar, had no real stock at all — but that turned out to be because those specific test items happen to be manufacturing components kept at the Delhi warehouse, not because of any problem with HB Akeda Dungar itself. **Confirmed with you directly: HB Akeda Dungar is the single warehouse used for all sales orders, and it holds all your real, sellable stock** — if something's out of stock there, it's out of stock everywhere. We re-tested with a real finished product to confirm this, and it worked correctly. No further action needed here.

**One related issue worth flagging directly to SalesOn support**: when an order is converted into a final tax invoice, the invoice always gets assigned to the "primary" warehouse regardless of what we tell it to use. Since the primary warehouse is the correct one anyway, this doesn't cause a practical problem for us — but it's still a real bug in SalesOn's system (ignoring an explicit instruction), worth mentioning to their support team.

## 6. Order status flow — confirmed, and better than we first thought

We created a real test order and walked it through every stage SalesOn actually supports: **Pending → Confirmed → Dispatched → Delivered** (plus Cancelled if needed), confirming each step actually works via a simple, single action — this is exactly what the "employee clicks Approve" step in your requirements will use under the hood.

We also tested the actual order-to-invoice step (dispatch team creates the real tax invoice) directly, and this cleared up something we'd flagged earlier as a gap:

- Converting an order to an invoice **automatically creates the invoice with its own invoice number, and links it back to the original order** — so item, quantity, party, and pricing stay correctly connected between the two, exactly as required. We don't need to build any extra bookkeeping for this ourselves; SalesOn already does it.
- The original order also automatically flips to a `"Invoiced"` status the moment this happens — **so "Invoice Generated" does have a real, live signal we can watch for after all**, correcting what we told you last update.
- The one stage that genuinely has no SalesOn equivalent is **"Order Packed/Processing"** — there's no real system signal for this anywhere. Our recommendation: keep it as a manual marker your staff can set on the website themselves (a note-to-self stage, not something SalesOn confirms automatically), or drop it from the tracker entirely if it's not essential. Let us know your preference.

Also good news: SalesOn gives every order (and now every invoice) its own shareable link (similar to the customer statement link we found earlier) — likely reusable for showing order/invoice details directly to customers/dealers rather than building a custom page from scratch.

## 7. Invoice PDF download — confirmed, fully working

We generated a real invoice PDF for our test transaction and it's genuinely good — your company logo and branding, GSTIN, itemized products with GST breakdown, bank details, terms and conditions, all correctly formatted. This directly delivers the "Invoice PDF / Download option" from your requirements — nothing further to validate here, it's ready to build against.

## 7a. Important finding: SalesOn does not enforce credit limits on its own

We tested this directly: created a test order for an amount roughly **50 times** our test customer's credit limit, and SalesOn let it through with no warning — both at order creation and at the "confirm" step. This isn't a flaw in the plan — the website was always designed to be the thing that checks credit before allowing staff to approve an order, precisely because we suspected SalesOn wouldn't do this itself. This test just confirms that assumption directly: **the credit check has to happen on the website's side, every time, since SalesOn will accept an order of any size regardless of the customer's actual limit.**

## 8. Bilty/LR (transport document) tracking — confirmed not possible automatically

We tested this directly: created a real test shipment and looked for any field that could hold a transport document or LR number — there isn't one anywhere, on the shipment or otherwise. Combined with a full review of SalesOn's own official documentation (zero mentions of "Bilty" or "LR" anywhere across it), we're confident this isn't just a documentation gap — SalesOn genuinely doesn't support tracking these documents through the API today.

**Practical result:** the Bilty/LR number and transporter details will need to be entered manually by your staff on the website's employee dashboard once dispatch happens (referencing the physical paperwork), rather than syncing automatically from SalesOn. This was already the fallback plan from the start — this testing just confirms it's the right call, not a workaround we're settling for without checking.

## 9. Payments — good news, two-way sync works

We created a real test payment, confirmed it appears correctly, approved it, watched the customer's balance update in real time (₹500 → ₹495), then deleted it and confirmed the balance correctly went back to ₹500. **This confirms two-way payment sync is genuinely possible** — one of the open questions (F) from your original requirements list.

One thing we learned that will matter for how staff use this: a payment doesn't count against a customer's balance the moment it's entered — it needs to be explicitly **approved** first (a second step). We'll build this into the plugin so it happens automatically as part of entering a payment, rather than as an extra manual step for your staff.

## 10. Technical note: found and fixed a way our own website could have silently broken

While testing how our website should ask SalesOn for information, we found that one of the exact request formats shown in SalesOn's own official documentation actually causes a server error on their end when used as documented. We identified the correct working format instead, so this won't cause any hidden failures once the real plugin is built.

## 11. Request volume — no issues found

We fired 110 requests at SalesOn back-to-back to see how it handles heavier traffic. It handled all of them without any slowdown or blocking, even faster than the rate their own documentation says is the limit. The regular background sync we're planning (checking for stock/order updates every 15 minutes) will be nowhere near this volume, so this isn't a concern.

## Phase 0 complete

Every item we set out to check — and the several extra ones the work itself surfaced along the way — has now been tested directly against your real SalesOn account, not just assumed from documentation. This report, together with the open questions above (which warehouse(s) hold real sellable stock; how to treat the 54 website products with no SalesOn match; whether to drop or manually track the "Packed/Processing" order stage), is what we need your sign-off on before moving into actually building the plugin.

We'll keep this document updated as each of these is confirmed.
