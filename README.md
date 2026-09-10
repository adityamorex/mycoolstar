# MyCoolStar — SalesOn ↔ WooCommerce Integration

Konstrukt / MyCoolStar SalesOn-WooCommerce integration project. See
`MyCoolStar-SalesOn-Integration-Plan.pdf` and `technical-implementation-plan.md`
for the full project background.

## Setup on a new machine

1. Clone this repo.
2. Python scripts (`phase0/*.py`):
   ```
   pip install -r requirements.txt
   ```
3. Node dependency (`php-parser`, used for PHP source analysis of the
   WooCommerce plugin):
   ```
   npm install
   ```
4. Credentials — copy the template and fill in real values (never commit
   the filled-in file; it's git-ignored):
   ```
   cp phase0/.env.local.example phase0/.env.local
   ```
   Required values: `SALESON_TOKEN`, `SALESON_COMPANY_ID`, `SALESON_BASE_URL`,
   `WOOCOMMERCE_CONSUMER_KEY`, `WOOCOMMERCE_CONSUMER_SECRET`,
   `WOOCOMMERCE_BASE_URL`.

## Project layout

- `phase0/` — discovery/spike scripts, plans, and working data for the
  integration's early phase (product mapping, parties reconciliation, order
  cleanup).
- `wp-content/plugins/saleson-woo-sync.zip` — the WordPress/WooCommerce
  plugin being developed.
- `plans/`, `wireframes/` — planning artifacts.
- Root-level `.md`/`.pdf`/`.docx` files — research reports and the
  client-facing integration plan.

## Note

This repo is **public**. Never commit `phase0/.env.local`, anything under
`phase0/logs/`, or any file containing real customer/order PII — check
before adding new data exports.
