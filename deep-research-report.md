# Executive Summary

This integration project requires a **comprehensive documentation-driven approach**. We will create a suite of documents covering project planning, discovery, technical design, testing, deployment, and maintenance. Each document has a clear purpose, content outline, template or example, priority, owner, time estimate, and acceptance criteria. Visual assets (Mermaid diagrams, example tables, UI mockups) will clarify the design. This ensures every decision is traceable and testable.  

**Key points:**  
- **Project Docs:** Charter, scope, stakeholders, schedule – set objectives, roles, and timelines.  
- **Discovery:** As‑is process flows, stakeholder interviews, and question logs capture current business reality.  
- **Technical Inventory:** Hostinger/WooCommerce audit (version, plugins, DB, code), SalesOn API catalog (endpoints, data models, Postman collection) and data dictionary for all entities.  
- **Mapping Artifacts:** Source-of-truth matrix, canonical data model, entity-relationship diagrams (ERDs) and data mappings link WooCommerce and SalesOn data.  
- **Event & Sequence Diagrams:** Map every trigger and flow (e.g. “Woo Order → SalesOn Invoice”) with Mermaid sequence diagrams.  
- **Integration Design:** API mapping (which Woo events call which SalesOn endpoints), error handling (HTTP 4xx/5xx rules), retry/backoff with jitter, idempotency for invoicing/payments, authentication (API keys or OAuth), rate-limiting strategies, and polling vs webhooks trade-offs.  
- **Plugin Design:** Architecture doc (diagram of components), class/module list, custom DB schema, and admin UI wireframe images. The plugin runs entirely in WordPress (no extra server), so references from the WordPress Plugin Handbook apply.  
- **Testing:** Test plan (scope, approach), detailed test cases (CRUD, sync, error cases), integration tests, sandbox strategy (using test accounts), and rollback plan for failed syncs.  
- **Deployment & Ops:** Deployment checklist, backup/restore procedures, monitoring/logging/alerting strategy, and runbook for on-call procedures.  
- **Security & Compliance:** Credential management (secrets store, least privilege), GDPR/GST data handling, and plugin security best practices.  
- **Data Migration & Cleanup:** Plan for test data setup and scripts to clean up any test data in production SalesOn.  
- **Acceptance & Sign-off:** UAT plan, success metrics (e.g. “99% of orders sync within 5 mins”), SLA for ongoing support.  
- **Maintenance:** Support model (responsible roles), post-launch runbook, and change-log process.  

Each document will be assigned a clear filename (e.g. `project-charter.md`), owner (PM, BA, developer, etc.), time estimate, and acceptance criteria. In the first 2 weeks, we will focus on **process mapping, inventories, and design documentation**.  

## 2-Week Documentation Checklist

1. **[Must] Project Charter (`docs/project-charter.md`):** Goals, scope, stakeholders, timeline.  
2. **[Must] Scope Statement (`docs/scope.md`):** In/out of scope, deliverables.  
3. **[Must] Stakeholder Register (`docs/stakeholders.xlsx`):** Names, roles, contacts.  
4. **[Must] Schedule / Timeline (`docs/timeline.gantt` or `docs/schedule.xlsx`):** High-level milestones.  
5. **[Must] As-Is Business Process (`docs/as-is-process.md` + diagram):** Customer order, approvals, invoicing flow.  
6. **[Must] Interview Notes & Questions (`docs/interviews.md`):** Key stakeholder Q&A, open issues.  
7. **[Must] WooCommerce Audit (`docs/woo-audit.md`):** WP version, theme, plugins, custom code, data stats.  
8. **[Must] SalesOn API Catalog (`docs/saleson-api-catalog.md`):** Endpoints, methods, descriptions (from Postman).  
9. **[Must] Data Dictionary (`docs/data-dictionary.md`):** Fields for Products, Customers, Orders, Invoices, Payments, etc.  
10. **[Must] Source-of-Truth Matrix (`docs/source-of-truth.xlsx`):** Which system (Woo or SalesOn) owns each field.  
11. **[Must] Entity-Relationship Diagram (`docs/er-diagram.md`):** Mermaid ER showing relationships (see example below).  
12. **[Must] Event Mapping (`docs/event-mapping.md`):** Triggers (Woo order, payment) → actions (call SalesOn API).  
13. **[Should] Integration Design (`docs/integration-design.md`):** API mappings, error/IDEMP, auth, rate limits.  
14. **[Should] Plugin Architecture (`docs/plugin-architecture.md`):** UML/component diagram, class list, DB tables.  
15. **[Should] Admin UI Wireframes (`docs/admin-ui.png`):** Screens for settings pages (embed images).  
16. **[Should] Test Plan (`docs/test-plan.md`):** Test strategy, environments (include sandbox), key cases.  
17. **[Should] Deployment Checklist (`docs/deploy-checklist.md`):** Pre/post-deploy steps, rollback.  
18. **[Should] Backup/Restore Plan (`docs/backup-restore.md`):** How to backup Woo DB, SalesOn DB snapshot.  
19. **[Should] Monitoring & Alerting (`docs/monitoring.md`):** Metrics to watch (sync failures, API errors).  
20. **[Should] Runbook (`docs/runbook.md`):** On-call steps for incidents.  
21. **[Nice] Security & Compliance (`docs/security.md`):** Secrets handling, data retention, GDPR/GST notes.  
22. **[Nice] UAT Plan (`docs/uat-plan.md`):** User acceptance steps, sign-off criteria.  
23. **[Nice] Data Cleanup Scripts (`scripts/cleanup_saleson_test.sh`):** For removing test records.  
24. **[Nice] Change Log Template (`docs/change-log.md`):** To record future updates.  

We will produce core “must-have” docs first (items 1–12) before any coding. Each doc will have sections/fields as shown below, written in Markdown (or XLSX for tables/schedules). Citations and examples appear in the relevant sections.  

---

# Document Catalog

Below is a detailed list of each required document, with filename suggestions, purpose, contents, templates, priority, effort, owner, and acceptance criteria.

## 1. Project Charter  
- **Filename:** `project-charter.md`  
- **Purpose:** Officially initiate the project. Defines objectives, scope, high-level deliverables, stakeholders, and success criteria. Provides sponsors and team a clear “project at a glance”.  
- **Sections:**  
  - **Project Title & ID**  
  - **Overview/Purpose:** One-paragraph business justification.  
  - **Objectives:** Bullet list of high-level goals.  
  - **Scope:** Brief in-scope / out-of-scope.  
  - **Stakeholders:** List of key roles (Sponsor, PM, Dev, Client POC).  
  - **Constraints/Assumptions:** e.g. “Use existing infrastructure; must use SalesOn API”.  
  - **Timeline/Milestones:** High-level dates (e.g. “Dec 2026 – Go-live”).  
  - **Success Criteria:** e.g. “All orders sync without error for 30 days”.  
- **Template Example (Markdown):**  
  ```markdown
  # Project Charter: WooCommerce–SalesOn Integration
  **Date:** 2026-07-18  
  **Project Sponsor:** Acme Corp Director of IT  
  **Project Manager:** [Name]  
  **Business Need:** Enable customers to place B2B orders online; eliminate manual ERP entry.  
  **Objectives:** 
  - Deploy WooCommerce portal integrated with SalesOn ERP  
  - Sync products, stock, orders, invoices, and payments automatically  
  - Reduce manual entry by 90% and improve order cycle time by 50%.  
  **In-Scope:** WooCommerce customization, SalesOn API integration, migration of master data.  
  **Out-of-Scope:** Full redesign of website, non-core SalesOn modules, alternative payment gateways (if any).  
  **Milestones:** 
  - Requirements locked: Week 2  
  - Alpha plugin ready: Month 1  
  - QA complete: Month 2  
  - UAT/Training: Month 3  
  - Go-live: Month 4  
  **Success Criteria:** 
  - 100% of placed orders appear in SalesOn.  
  - No payment mismatch (Payment reconciliation report matches).  
  ```  
- **Priority:** Must-have (project kickoff).  
- **Effort:** ~2–4 hours.  
- **Owner:** Project Manager or Lead Developer.  
- **Acceptance:** Stakeholders confirm it accurately captures project purpose, scope, and key dates.   

## 2. Scope Statement  
- **Filename:** `scope.md`  
- **Purpose:** Elaborates on project scope in detail to avoid scope creep. Defines deliverables, boundaries, and constraints.  
- **Sections:**  
  - **Introduction:** Restate project context and objectives.  
  - **In Scope:** Detailed list (e.g. “Customer registration integration”, “Sync of stock levels every 5 minutes”).  
  - **Out of Scope:** Clearly exclude (e.g. “No UI overhaul beyond integration pages”, “No offline payments”).  
  - **Deliverables:** Specific outputs (plugin code, documentation, training).  
  - **Constraints & Assumptions:** E.g., “API keys limited to 10 req/sec”, “Client will provide SalesOn credentials”.  
- **Template Snippet:**  
  ```markdown
  # Scope Statement
  ## In Scope
  - WooCommerce plugin for SalesOn integration (order, product, customer sync)  
  - Settings pages for mapping fields and API credentials  
  - Data dictionary and initial data mapping documentation  
  - End-to-end testing with dummy data  
  ## Out of Scope
  - Re-engineering of SalesOn workflows  
  - Custom plugin features unrelated to SalesOn (e.g. marketing pop-ups)  
  - Non-ERP integrations (no CRM or accounting).  
  ```  
- **Priority:** Must-have (defines limits of work).  
- **Effort:** ~2–3 hours.  
- **Owner:** Project Manager / Analyst.  
- **Acceptance:** Clients agree on what’s included/excluded; sign-off on this doc.  

## 3. Stakeholder Register  
- **Filename:** `stakeholders.xlsx` (or `.md` table)  
- **Purpose:** Record all individuals/groups involved or affected by project, with roles and contact info. Helps communication planning.  
- **Fields:**  
  - Name, Role (Sponsor, PM, Developer, QA, Client Admin, Sales rep), Organization, Contact Info, Responsibilities/Interest.  
- **Template (table):**  
  | Name           | Role               | Department    | Email              | Phone       | Responsibilities                     |
  |----------------|--------------------|---------------|--------------------|-------------|--------------------------------------|
  | Acme CEO       | Project Sponsor    | Exec         | sponsor@acme.com  | 9xxxxxxx    | Decision-maker, approval authority  |
  | IT Manager     | Project Owner      | IT           | it@acme.com       | 9xxxxxxx    | Oversees implementation, resources  |
  | Sales Head     | Business Stakeholder | Sales       | sales@acme.com    | 9xxxxxxx    | Defines business requirements        |
  | Lead Dev       | Technical Lead     | IT           | dev@acme.com      | 9xxxxxxx    | Plugin development, review          |
  | Integrator Dev | Developer          | Consultant   | integrator@you.io | 9xxxxxxx    | Plugin coding, documentation        |
  | ...            | ...                | ...           | ...                | ...         | ...                                  |
- **Priority:** Must-have (early, for communications).  
- **Effort:** ~1–2 hours.  
- **Owner:** Project Manager or Scrum Master.  
- **Acceptance:** All key stakeholders are identified and have reviewed their roles.  

## 4. Schedule / Timeline  
- **Filename:** `schedule.xlsx` or `schedule.gantt`  
- **Purpose:** High-level timeline with milestones and dependencies. Guides project progress.  
- **Sections:**  
  - **Milestones:** (Requirements complete, Dev complete, Testing, UAT, Go-live).  
  - **Tasks/Activities:** With start/end dates and responsible.  
- **Template:**  Gantt chart or list. For brevity, a simple task list example:  
  | Task                    | Start Date | End Date   | Owner             |
  |-------------------------|------------|------------|-------------------|
  | Requirements gathering  | 2026-07-20 | 2026-07-25 | Analyst, Client   |
  | Plugin design           | 2026-07-26 | 2026-07-30 | Dev               |
  | API Client coding       | 2026-07-31 | 2026-08-04 | Dev               |
  | Initial sync (products) | 2026-08-05 | 2026-08-09 | Dev               |
  | Integration testing     | 2026-08-10 | 2026-08-15 | QA                |
  | UAT                     | 2026-08-16 | 2026-08-20 | Client, QA        |
  | Deployment              | 2026-08-21 | 2026-08-22 | Dev, IT           |
- **Priority:** Must-have (for planning and stakeholder visibility).  
- **Effort:** ~2 hours.  
- **Owner:** Project Manager or Lead Dev.  
- **Acceptance:** Stakeholders agree dates/milestones; tool (e.g. Gantt) is up-to-date.  

## 5. As-Is Business Process  
- **Filename:** `as-is-process.md`  
- **Purpose:** Document current order/invoice/payment workflows with SalesOn (since WooCommerce site is unused). Identifies manual steps and pain points.  
- **Sections:**  
  - **Process Description:** Narrated steps (e.g. “Sales rep updates SalesOn, then generates invoice”).  
  - **AS-IS Diagram:** Mermaid process flow or sequence.  
  - **Pain Points:** Bulleted issues (manual entry, delays, errors).  
- **Template Example:**  
  ```markdown
  # Current (AS-IS) Order Process
  1. Customer places order via phone or email to Sales Team.  
  2. Sales rep logs into SalesOn ERP and creates Sales Order.  
  3. System checks stock (SalesOn inventory).  
  4. Sales rep approves order; SalesOn generates Invoice (#INVxxx).  
  5. Warehouse dispatches goods, enters LR details in SalesOn.  
  6. Customer makes payment (bank transfer); accountant posts payment in SalesOn.  

  ## Pain Points
  - **Latency:** Manual entry causes delays; customers wait for confirmation.  
  - **Errors:** Copying product codes/prices leads to mistakes.  
  - **Visibility:** Customers have no visibility into outstanding invoices or stock.  
  ```
  **Mermaid Process Example:**  
  ```mermaid
  sequenceDiagram
    actor Customer
    participant SalesTeam
    participant SalesOn
    Customer->>SalesTeam: Order request (phone/email)
    SalesTeam->>SalesOn: Create Sales Order
    SalesOn-->>SalesTeam: Order ID generated
    SalesTeam->>SalesOn: Approve Order, generate Invoice
    SalesOn-->>SalesTeam: Invoice PDF/Number
    SalesTeam->>Warehouse: Dispatch goods
    Warehouse->>SalesOn: Record LR/Dispatch info
    Customer->>Finance: Payment (Bank/UPI)
    Finance->>SalesOn: Record Payment
  ```
- **Priority:** Must-have (foundational knowledge).  
- **Effort:** ~4–6 hours (interviews + write-up).  
- **Owner:** Business Analyst or Integration Lead.  
- **Acceptance:** Business team confirms the documented flow accurately matches reality.  

## 6. Interview Notes & Questions Log  
- **Filename:** `interviews.md` (or `.xlsx`)  
- **Purpose:** Record stakeholder interview summaries and outstanding questions. Ensures no detail is overlooked.  
- **Sections:**  
  - **Interviews:** For each person (Name, role, date, key points).  
  - **Open Questions:** Compiled list of unresolved queries for follow-up (e.g. “Which system holds credit limit?”).  
- **Template Example:**  
  ```markdown
  # Stakeholder Interviews
  
  **Sales Team Lead (2026-07-19):**
  - Customers currently phone in orders.  
  - Stock is only in SalesOn; no website.  
  - Issues: double entry, delayed confirmations.  
  - **Action:** Find out if stock needs to be live on website.
  
  **Interview with Accounts (2026-07-20):**
  - Payments are recorded in SalesOn after bank statements.  
  - Outstanding report is daily in SalesOn.  
  - **Question:** Can outstanding be fetched via API?
  
  # Questions & Actions
  1. **Q:** Does SalesOn API provide invoice PDFs?  
     **A:** (To ask SalesOn support).  
  2. **Q:** Are there global discounts/schemes for dealers?  
     **A:** (Check SalesOn pricing logic).
  ```
- **Priority:** Must-have (drives design and scope).  
- **Effort:** ~4 hours (meetings + documentation).  
- **Owner:** Business Analyst / PM.  
- **Acceptance:** All stakeholders confirm notes; outstanding questions have owners and deadlines.  

## 7. WooCommerce Audit  
- **Filename:** `woo-audit.md`  
- **Purpose:** Inventory the existing WooCommerce site (dummy) to know platform versions, customizations, and gaps. Document will guide plugin compatibility and migrations.  
- **Sections:**  
  - **WordPress Info:** WP version, PHP version, theme name (Child theme?), WooCommerce version.  
  - **Plugins:** List all plugins and their purpose (especially any custom or checkout plugins).  
  - **Custom Code:** Any mu-plugins or theme overrides.  
  - **Data:** Count of products, categories, customers, orders (if any). Note SKUs if present.  
  - **Database:** Any custom tables (e.g. for inventory).  
- **Template Snippet:**  
  ```markdown
  # WooCommerce Site Audit
  - **WP Version:** 6.3.0, **PHP:** 8.1.  
  - **Theme:** Woodmart (Child theme present).  
  - **Active Plugins:** WooCommerce 9.2, Advanced Custom Fields (free), YITH Wishlist, Custom Payment Gateway, etc.  
  - **Customizations:** Custom checkout fields plugin; no current SalesOn integrations.  
  - **Product Count:** 50 dummy products, SKUs numeric 1001-1050 (not matching SalesOn).  
  - **Customers/Orders:** 0 (site unused).  
  - **APIs:** WooCommerce REST API available via keys for admin user “admin”.  
  ```  
- **Priority:** Must-have (precedes design).  
- **Effort:** ~2 hours (exploration + notes).  
- **Owner:** Developer / Technical Lead.  
- **Acceptance:** Details confirmed by accessing WP Admin; chart of plugins and data is complete.  

## 8. SalesOn API Catalog  
- **Filename:** `saleson-api-catalog.md`  
- **Purpose:** Document all relevant SalesOn API endpoints (from Postman collection and test calls) grouped by entity. Include URI patterns, methods, request/response models (fields). This is the integration “reference manual.”  
- **Sections:**  
  - **Authentication:** How to authenticate (API key, bearer token) and expiry.  
  - **Customers/Parties:** GET, POST, PUT endpoints and JSON schema (customer code, GST, name, etc).  
  - **Products/Inventory:** GET list, GET by SKU, etc; fields (product_id, sku, stock).  
  - **Orders/Invoices:** Endpoints to create order/invoice, list invoices, fields (order_id, item lines).  
  - **Payments:** Endpoints to create and query payments, fields (invoice_no, amount).  
  - **Dispatch:** Endpoints to update LR/Bilty.  
  - **Others:** (if needed, schemes, discounts).  
- **Template (table of endpoints):**  
  | Entity      | Method | Endpoint               | Description                            |
  |-------------|--------|------------------------|----------------------------------------|
  | Products    | GET    | `/items`               | List products (filter by SKU)          |
  | Products    | GET    | `/items/{id}`          | Get product details                   |
  | Customers   | GET    | `/party`               | List customers (filter by code/GST)    |
  | Customers   | GET    | `/party/{id}`          | Get customer details                  |
  | Orders      | POST   | `/sales-order`         | Create new SalesOn Order (in draft)    |
  | Invoices    | GET    | `/invoice`             | List invoices                          |
  | Invoices    | GET    | `/invoice/{no}`        | Get invoice by number                 |
  | Payments    | POST   | `/payment`             | Record a payment on an invoice         |
  | Dispatch    | POST   | `/builty`              | Create LR/Bilty entry                  |
  | ...         | ...    | ...                    | ...                                    |
- **Sample Payload (YAML):**  
  ```yaml
  # Example: Create SalesOn Order (SalesOnClient SDK stub)
  order:
    order_no: ''      # (auto-generated)
    order_date: 2026-07-20
    customer_code: "CUST123"
    items:
      - product_code: "PROD001"
        quantity: 10
        price: 100.00
  ```
- **Priority:** Must-have (drives implementation).  
- **Effort:** ~6 hours (extract from API docs + Postman analysis).  
- **Owner:** Developer / API Specialist.  
- **Acceptance:** All necessary endpoints are listed with correct payload examples; tested with dummy calls.  

## 9. Data Dictionary  
- **Filename:** `data-dictionary.md`  
- **Purpose:** List and define all relevant data fields/objects from WooCommerce and SalesOn. Helps map fields and design DB tables.  
- **Sections:** For each domain:  
  - **Products:** e.g. SKU (string), Description, Price, Stock, Image URL, etc.  
  - **Customers:** e.g. Name, Email, GSTIN, Mobile, Address, Customer Type.  
  - **Orders:** Woo: order_id, order_status, total; SalesOn: sales_order_id, invoice_no, amounts.  
  - **Invoice:** fields like invoice_no, date, total, due date.  
  - **Payments:** amount, date, payment method, reference.  
- **Template (table):**  
  | Field Name       | WooCommerce Field      | SalesOn Field         | Data Type      | Comments                        |
  |------------------|------------------------|-----------------------|----------------|---------------------------------|
  | Product SKU      | `product.sku`          | `item_code`           | string         | Unique product identifier       |
  | Product Price    | `product.price`        | `sales_rate`          | decimal        | SalesOn may have price lists    |
  | Stock Quantity   | (Woo stock)            | `quantity` (SalesOn)  | integer        | Woo stock is read-only          |
  | Customer Name    | `customer.billing.name` | `party_name`          | string         |                                 |
  | Customer GSTIN   | (custom ACF field)     | `gst_no`              | string         | SalesOn key for B2B customers   |
  | Order Total      | `order.total`          | `invoice_amount`      | decimal        |                                 |
  | Payment Status   | `order.status`         | (SalesOn has paid flag)| enum         | Map statuses (e.g. processing vs billed) |
- **Priority:** Must-have.  
- **Effort:** ~3 hours.  
- **Owner:** Developer / Analyst.  
- **Acceptance:** All relevant fields are defined and matched; used in mapping docs.  

## 10. Source-of-Truth Matrix  
- **Filename:** `source-of-truth.xlsx`  
- **Purpose:** Decide which system is authoritative for each piece of data. Prevents conflicts (e.g., SalesOn is true for stock and prices, Woo for product images).  
- **Columns:** Entity, Field, Source (Woo/SalesOn), Direction (→ target).  
- **Template Example:**  

  | Entity      | Field                | Source System | Target System | Notes                         |
  |-------------|----------------------|---------------|---------------|------------------------------|
  | Product     | Name                 | WooCommerce   | -             | Website content masters name  |
  | Product     | SKU                  | SalesOn       | WooCommerce   | SalesOn uses SKU as code      |
  | Product     | Price                | SalesOn       | WooCommerce   | Real-time price from ERP      |
  | Product     | Stock Quantity       | SalesOn       | WooCommerce   | Sync every 5 min              |
  | Product     | Image URL            | WooCommerce   | -             | Only on site                  |
  | Customer    | Address              | SalesOn       | WooCommerce   | Single source (SalesOn)       |
  | Customer    | Group/Type           | SalesOn       | WooCommerce   | B2B tier affects pricing      |
  | Order       | Status               | WooCommerce   | SalesOn       | Order created in Woo, then ERP|
  | Invoice     | Number               | SalesOn       | WooCommerce   | After billing, push to site   |
  | Payment     | Amount               | SalesOn       | WooCommerce   | From ERP, or from online PGW  |

- **Priority:** Must-have (critical mapping guide).  
- **Effort:** ~2 hours.  
- **Owner:** Developer / Analyst.  
- **Acceptance:** Reviewed with stakeholders; no ambiguity on which side controls each field.  

## 11. Entity-Relationship Diagram (ERD)  
- **Filename:** `er-diagram.md` (Mermaid code) or as image.  
- **Purpose:** Visualize how entities (Customer, Product, Order, Invoice, Payment) relate across systems. Clarifies foreign keys.  
- **Content:** A Mermaid ER diagram. E.g.:  
  ```mermaid
  erDiagram
    CUSTOMER ||--o{ ORDER : places
    CUSTOMER ||--o{ INVOICE : billed
    ORDER ||--|{ ORDER_ITEM : contains
    ORDER_ITEM }|..|{ PRODUCT : refers
    ORDER ||--|{ INVOICE : generates
    INVOICE ||--o{ PAYMENT : collects
    PRODUCT ||--o{ INVOICE : includes
  ```
- **Priority:** Must-have (for design clarity).  
- **Effort:** ~1 hour.  
- **Owner:** Architect/Lead Developer.  
- **Acceptance:** ERD correctly represents relationships; peer-reviewed.  

## 12. Event Mapping & Sequence Diagrams  
- **Filename:** `event-mapping.md` and embedded Mermaid diagrams (or separate `.mmd`).  
- **Purpose:** List all integration events/triggers and flows. Each event (e.g. “Woo Order Completed”) mapped to actions (e.g. “Call SalesOn create order API”). Sequence diagrams illustrate timing.  
- **Sections:**  
  - **Event Table:**  
    | Event                 | Source System      | Trigger Condition          | Action (Target)               |
    |-----------------------|--------------------|----------------------------|-------------------------------|
    | Customer Signup       | WooCommerce        | New user registers/approved | Call SalesOn Create Party     |
    | Order Placed          | WooCommerce        | Order status “processing”  | Call SalesOn createSalesOrder |
    | Invoice Posted        | SalesOn            | Invoice generated          | Update Woo Order status to “completed” and attach invoice no |
    | Payment Received      | SalesOn            | Payment recorded           | (Optional) Update Woo order/payment status |
    | Stock Change          | SalesOn            | Inventory updated          | Update Woo stock via cron     |
  - **Sequence Diagrams (Mermaid):**  
    ```mermaid
    sequenceDiagram
      participant C as Customer
      participant W as WooCommerce
      participant P as Plugin
      participant S as SalesOn

      C->>W: Place Order (Checkout)
      W->>P: on_order_status_processing
      P->>S: POST /sales-order (create order)
      S-->>P: 200 OK (SalesOn order no)
      P->>W: mark order as "synced with SalesOn"
    ```
- **Priority:** Must-have.  
- **Effort:** ~3 hours.  
- **Owner:** Integration Developer / Architect.  
- **Acceptance:** All key flows diagrammed; business approves accuracy.  

## 13. Integration Design Document  
- **Filename:** `integration-design.md`  
- **Purpose:** Detailed plan of how the integration works end-to-end. Covers API usage, data flow, and technical constraints.  
- **Sections:**  
  - **High-Level Architecture:** Block diagram (e.g. Woo→Plugin→SalesOn).  
  - **API Mappings:** Table matching Woo events to SalesOn endpoints.  
  - **Authentication:** How API keys or tokens are stored/used.  
  - **Error Handling:** Use standard HTTP codes; distinguish 4xx vs 5xx. E.g. on 4xx fail-fast, on 5xx retry with backoff.  
  - **Retry/Backoff:** Exponential backoff with jitter for 5xx/429.  
  - **Idempotency:** Strategy for no-duplicates (use unique clientOrderNo per SalesOn write or check by lookup).  
  - **Rate Limiting:** Observe `Retry-After` header; throttle if needed.  
  - **Batching:** e.g., if many products, page through APIs with pagination.  
  - **Data Integrity:** Maps (see Source-of-Truth); any transformation (e.g. date formats, rounding).  
  - **Webhooks vs Polling:** Does SalesOn support webhooks? If not, use polling/cron. Trade-offs noted. E.g. “If no webhook for payments, poll every 5 minutes.”  
- **Priority:** Should-have (guides coding standards).  
- **Effort:** ~4–6 hours.  
- **Owner:** Lead Developer / Integration Architect.  
- **Acceptance:** Technical lead agrees with design; addresses all key issues.  

## 14. Plugin Architecture & Class Diagram  
- **Filename:** `plugin-architecture.md`  
- **Purpose:** Design of the WordPress plugin structure and main components. Clarifies responsibilities of each class/module.  
- **Sections:**  
  - **Overview:** Explain the plugin’s sub-systems (e.g. API Client, Sync Engine, Admin UI, CRON tasks, Data Storage).  
  - **UML/Block Diagram:** High-level module interaction.  
  - **Classes/Modules List:** Table with class names and purpose.  
  - **Database Schema:** If using custom tables (for mapping Woo↔SalesOn IDs, logs), include a schema.  
- **Template (class list):**  
  | Class/Module           | Responsibility                            |
  |------------------------|-------------------------------------------|
  | `SalesOnClient`        | Handles HTTP calls to SalesOn API (REST). |
  | `SyncManager`          | Orchestrates syncs (products, invoices).  |
  | `OrderSync`            | Sync Woo orders to SalesOn.               |
  | `InvoiceSync`          | Pull SalesOn invoices into Woo orders.    |
  | `PaymentSync`          | Sync SalesOn payments.                    |
  | `PluginSettingsPage`   | Admin UI for entering API keys/mappings.  |
  | `CronScheduler`        | Registers WordPress cron events.          |
  | `DataMapper`           | Maps fields between systems (if needed).  |
- **DB Schema Snippet (SQL):**  
  ```sql
  CREATE TABLE {$wpdb->prefix}saleson_mapping (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    woo_id BIGINT NOT NULL,
    saleson_id VARCHAR(50) NOT NULL,
    entity ENUM('product','customer','order','invoice') NOT NULL,
    synced_at DATETIME
  );
  ```
- **Priority:** Should-have.  
- **Effort:** ~3 hours.  
- **Owner:** Lead Developer.  
- **Acceptance:** Technical review confirms completeness (e.g. covering all sync paths).  

## 15. Admin UI Wireframes  
- **Filename:** `admin-ui.png` or `.md` with images.  
- **Purpose:** Visual mockups of plugin settings pages and any admin screens (e.g. “Sync Logs” page). Ensures alignment before coding UI.  
- **Content:**  
  - **Settings Page:** Fields for API credentials, sync intervals, mappings.  
  - **Logs Page:** Table of recent sync operations with status.  
- **Example (textual mock):**  
  *“SalesOn Integration Settings” page:*  
  - Text fields: API Key, API Secret, SalesOn URL.  
  - Dropdowns: Map Woo Order Status → SalesOn Order Status.  
  - Checkbox: Auto-sync stock, Auto-sync invoices.  
  - “Save Settings” button.  
  - (Include an actual diagram: For example, embed a simple drawn image with labels).
- **Priority:** Should-have (for UI dev guidance).  
- **Effort:** ~2 hours (sketching in pencil or a tool).  
- **Owner:** UX/Developer.  
- **Acceptance:** Stakeholder approval of layout before implementation.  

## 16. Test Plan  
- **Filename:** `test-plan.md`  
- **Purpose:** Outline testing strategy: scope of integration tests, system tests, UAT, performance tests, and criteria for success.  
- **Sections:**  
  - **Scope:** What will be tested (functional sync, data integrity, error scenarios).  
  - **Environments:** Use Woo staging site, SalesOn test instance (if available); otherwise use production in read-only mode.  
  - **Types of Testing:** Unit tests (for client code), integration tests (Woo↔SalesOn flows), UAT (client walkthrough).  
  - **Test Data Setup:** Use dummy customer and product (created earlier).  
  - **Pass/Fail Criteria:** E.g. “New order on Woo appears as invoice in SalesOn within 5 minutes”.  
- **Priority:** Should-have.  
- **Effort:** ~3 hours.  
- **Owner:** QA Engineer / Developer.  
- **Acceptance:** QA lead signs off; covers all integration scenarios.  

## 17. Test Cases  
- **Filename:** `test-cases.xlsx`  
- **Purpose:** Detail test cases with steps, inputs, expected results. For regression and UAT.  
- **Columns:** Test ID, Description, Precondition, Steps, Expected Outcome, Pass/Fail.  
- **Sample Rows:**  
  | ID  | Description                                | Steps                                        | Expected Result                             |
  |-----|--------------------------------------------|----------------------------------------------|---------------------------------------------|
  | TC1 | Customer self-register (B2B)               | 1. Go to signup; 2. Fill valid GST/email; 3. Submit | SalesOn API called to create customer; new party in SalesOn |
  | TC2 | Sync new product from SalesOn to Woo       | 1. Add product in SalesOn with SKU=PROD1; 2. Run cron | Woo shows new product PROD1 with correct stock |
  | TC3 | Place order in Woo and sync to SalesOn     | 1. As customer, place order for SKU PRODX; 2. Check SalesOn | New SalesOrder created with matching items; Woo order note updated |
  | TC4 | Handle network failure during API call     | 1. Simulate timeout on SalesOn API; 2. Retry manually   | Plugin retries with backoff; no duplicate records created |
- **Priority:** Should-have.  
- **Effort:** ~4 hours.  
- **Owner:** QA Engineer.  
- **Acceptance:** All critical paths covered; stakeholders review.  

## 18. Sandbox Testing Strategy  
- **Filename:** `sandbox-strategy.md`  
- **Purpose:** Plan for safe testing (using dummy records) to avoid polluting production. This includes making test accounts/products and how to clean up.  
- **Sections:**  
  - **Test Data:** Names/codes for dummy customer and product (e.g. prefix “TEST_”).  
  - **SalesOn Modality:** Use one SalesOn “Company” for testing or ensure test markers.  
  - **Woo Test Environment:** Use a staging copy of WooCommerce or flag dummy users (maybe user role “Wholesale/Test”).  
  - **Risk Mitigation:** Steps to avoid live data (e.g. disable payment gateway that charges real money).  
- **Priority:** Must-have (mitigate risk).  
- **Effort:** ~2 hours.  
- **Owner:** Developer / PM.  
- **Acceptance:** No test activity should accidentally post live orders/invoices. Review with client.  

## 19. Rollback & Cleanup Plan  
- **Filename:** `rollback-plan.md` (or `.txt`)  
- **Purpose:** Steps to revert changes if something goes wrong (e.g. disable plugin, remove test data). Ensures quick recovery from errors.  
- **Sections:**  
  - **Plugin Rollback:** “Deactivate plugin via WP admin or CLI.”  
  - **SalesOn Cleanup Script:** e.g. SQL or API calls to delete test orders (caution!).  
  - **Backup Restores:** Using backup snapshots from before deployment.  
- **Priority:** Should-have.  
- **Effort:** ~2 hours.  
- **Owner:** DevOps Engineer / Dev.  
- **Acceptance:** Clear, tested instructions exist for critical rollback scenarios.  

## 20. Deployment Checklist  
- **Filename:** `deploy-checklist.md`  
- **Purpose:** Step-by-step tasks to do during deployment, ensuring nothing is missed.  
- **Sections:**  
  - **Pre-Deploy:** (“Ensure backup taken”, “Notify users” etc).  
  - **Production Plugin Install:** Steps to upload and activate plugin.  
  - **Data Migration:** (If needed, e.g. initial product sync).  
  - **Post-Deploy:** Test orders, check logs, etc.  
- **Sample Checklist:**  
  - Take full backup of WP site and SalesOn DB.  
  - Verify SalesOn API credentials are current.  
  - Disable non-essential cron jobs (if any).  
  - Upload plugin ZIP, activate it.  
  - Enter API keys in settings page. Save.  
  - Run manual sync tasks: Products, Customers. Verify.  
  - Place a test order. Check SalesOn invoice.  
  - Reactivate any disabled cron.  
- **Priority:** Should-have.  
- **Effort:** ~2 hours.  
- **Owner:** DevOps / Developer.  
- **Acceptance:** Checklist covers all steps; successfully used in test deploy.  

## 21. Backup & Restore Plan  
- **Filename:** `backup-restore.md`  
- **Purpose:** Document how to backup WordPress (files + DB) and SalesOn data, and how to restore them if needed.  
- **Sections:**  
  - **WooCommerce Backup:** e.g. using Hostinger’s control panel or WP CLI (`mysqldump`).  
  - **SalesOn Backup:** If they have export tools or DB snapshot (ask client).  
  - **Frequency:** How often to schedule backups (daily?).  
- **Priority:** Should-have (for disaster recovery).  
- **Effort:** ~2 hours.  
- **Owner:** Ops/Infrastructure.  
- **Acceptance:** Verified process: Restoring from most recent backup recovers system.  

## 22. Monitoring & Alerting Plan  
- **Filename:** `monitoring.md`  
- **Purpose:** Define metrics/logs to monitor (e.g. sync failures, time to sync, PHP errors) and how alerts are delivered (email or Slack).  
- **Sections:**  
  - **What to Monitor:** Cron job runs, plugin error logs, API response codes, WP error logs.  
  - **Tools:** WP logging (monolog?), server logs, any APM.  
  - **Alert Rules:** e.g. “Notify Dev when >5 failures in 1 hr” or “Payment sync errors send email”.  
- **Priority:** Should-have.  
- **Effort:** ~3 hours.  
- **Owner:** DevOps / Developer.  
- **Acceptance:** Alerts tested; team knows response process.  

## 23. Runbook  
- **Filename:** `runbook.md`  
- **Purpose:** Day-to-day operational guide for Dev/Ops. Steps for common tasks (clearing cache, resetting sync jobs), and triaging issues.  
- **Sections:**  
  - **Starting/Restarting Sync:** How to manually trigger or clear stuck queues.  
  - **Viewing Logs:** Location of plugin log files or WP_DEBUG logs.  
  - **Contacts:** Who to contact if issues (Dev, SalesOn support).  
- **Priority:** Should-have.  
- **Effort:** ~3 hours.  
- **Owner:** Lead Developer / SysAdmin.  
- **Acceptance:** The team can resolve at least 5 common incidents using the runbook.  

## 24. Security & Compliance Guidance  
- **Filename:** `security.md`  
- **Purpose:** Outline security measures and any compliance requirements (e.g. data protection for customer info, GST/invoice rules).  
- **Sections:**  
  - **Credentials:** Store SalesOn API keys securely (never in code). Use WP options with `update_option( 'saleson_key', $key );` (with encryption or `.env` if possible).  
  - **Access Control:** WP user roles – ensure only Admins can change settings. Use `current_user_can('manage_woocommerce')`.  
  - **Data Protection:** Follow GDPR if EU customers (use built-in WP Personal Data tools). For GST data, ensure encryption at rest if needed.  
  - **Plugin Security:** Nonces and capability checks on forms (per WordPress Handbook).  
- **Priority:** Should-have.  
- **Effort:** ~2 hours.  
- **Owner:** Security Engineer / Developer.  
- **Acceptance:** No sensitive info in logs/config; peer review of security measures.  

## 25. Data Migration & Cleanup Plan  
- **Filename:** `data-migration.md`  
- **Purpose:** Plan for migrating or aligning any existing data. For example, if converting some existing Woo products to sync with SalesOn, or cleaning dummy data.  
- **Sections:**  
  - **Identify Test Data:** List test accounts/products created.  
  - **Cleanup Procedures:** Scripts to delete records by their test codes (e.g. via SalesOn API).  
  - **Production Cutover (if any):** If copying SalesOn products to Woo initially.  
- **Priority:** Nice-to-have (but critical if data already exists).  
- **Effort:** ~2 hours.  
- **Owner:** Developer.  
- **Acceptance:** Test cleanup script removes only intended data.  

## 26. UAT Plan & Success Metrics  
- **Filename:** `uat-plan.md`  
- **Purpose:** Describe user acceptance testing approach and define how success is measured for go-live.  
- **Sections:**  
  - **Test Scenarios:** (simple list, refer to test cases).  
  - **Participants:** Which client users will test (Sales, Accounts, etc).  
  - **Duration:** Timeline for UAT.  
  - **Sign-off Criteria:** E.g. “No critical defects outstanding; 100% of critical test cases passed.”  
  - **Metrics:** “Orders per day sync without error”, “<5% customer support incidents”, or business KPIs (increase in order volume).  
- **Priority:** Should-have.  
- **Effort:** ~2 hours.  
- **Owner:** QA Lead / PM.  
- **Acceptance:** Stakeholders agree on sign-off criteria.  

## 27. Change Log Template  
- **Filename:** `change-log.md`  
- **Purpose:** Provide a standard way to record updates after go-live (features, bug fixes). Helps maintenance.  
- **Content:** Could be a Markdown table or list: Date, Version, Changes, Author.  
- **Example:**  
  ```markdown
  # Change Log

  | Date       | Version | Author         | Changes                              |
  |------------|---------|----------------|--------------------------------------|
  | 2026-07-20 | 0.1     | DevName        | Initial draft of documents           |
  | 2026-07-30 | 1.0     | DevName        | Plugin released (alpha)              |
  | ...        | ...     | ...            | ...                                  |
  ```
- **Priority:** Nice-to-have (helps future dev).  
- **Effort:** ~1 hour to set up.  
- **Owner:** Developer/PM.  
- **Acceptance:** Change log is maintained for all releases/updates.  

## 28. Support & Maintenance Plan  
- **Filename:** `support-model.md`  
- **Purpose:** Define post-launch support: who is responsible for bugs, response times, how to log issues.  
- **Sections:**  
  - **Support Model:** e.g. “Triage by our team, escalate to plugin dev if needed.”  
  - **Issue Tracking:** Use Jira or GitHub issues, explain labeling.  
  - **Escalation:** Contacts for high-severity issues.  
- **Priority:** Nice-to-have.  
- **Effort:** ~2 hours.  
- **Owner:** PM / Developer.  
- **Acceptance:** Clear process that client agrees to.  

---

### Visual Assets Summary

- **ER Diagram (Mermaid):** Illustrates data model (see Section 11).  
- **Sequence Diagrams (Mermaid):** Key flows (see Section 12).  
- **Admin UI Mockups:** Wireframe images of settings and logs pages (Section 15).  
- **Example Tables:** Shown above (source-of-truth, stakeholders, endpoints, etc.).  

Each diagram or image should be captioned and explained in the docs. We may include code snippets for integration logic or JSON/YAML payload samples where helpful. 

# Sources

Documentation and best practices from WordPress and integration experts inform this plan: the [WordPress Plugin Handbook] guides secure plugin architecture; WooCommerce [REST API docs] ensure proper use of endpoints; and API integration patterns (idempotency, error/backoff) come from authoritative sources. General project doc standards are drawn from industry [Project Documentation] guidelines. 

