# Forgeline Marketplace - Architecture & Design Document

## 1. Boundary Ownership

To prevent data mismatch across the network, we establish explicit boundaries for every core platform entity:
```text
┌───────────────────────────────┐     ┌───────────────────────────────┐     ┌───────────────────────────────┐
│     ADOBE COMMERCE STORE      │     │      INTEGRATION SERVICE      │     │      SELLAXIS MARKETPLACE     │
├───────────────────────────────┤     ├───────────────────────────────┤     ├───────────────────────────────┤
│ Master of:                    │     │ Master of:                    │     │ Master of:                    │
│ - Customer Authentications    │     │ - Idempotency Registries      │     │ - Vendor Profiles             │
│ - Live Basket Valuations      │ ──> │ - Error Dead-Letter Logs      │ <── │ - Base Marketplace Catalog    │
│ - Primary Payment Ingestion   │     │ - Queue Token Routing         │     │ - Master Dropship Inventory   │
└───────────────────────────────┘     └───────────────────────────────┘     └───────────────────────────────┘
```

- **Adobe Commerce (Magento 2):** Source of truth for the **buyer storefront**, **shopping cart**, **checkout**, **customer (buyer) accounts**, and **initial payment capture**. It holds the canonical unified product catalogue (180,000 distinct products) but is *not* the source of truth for seller inventory or seller offers.
- **Sellaxis (Marketplace Platform):** Source of truth for **seller identity, seller catalog (offers), seller inventory limits, and seller-level order fulfillment statuses**.
- **Marketplace Integration Layer (Forgeline Module):** The orchestration layer residing inside Magento. It owns **idempotency, webhook ingestion, async queue management, and state machine orchestration** between Magento, Sellaxis, ShipBridge, and the ERP.
- **Operator ERP:** Source of truth for **financial settlement**, accounts payable (seller payouts), accounts receivable (buyer invoicing), and marketplace P&L.
- **ShipBridge:** Source of truth for **shipping rates, label generation, and carrier tracking statuses**.

## 2. End-to-End Flows
- **Order & Payment Flow:** Buyer (from 40,000 accounts) checks out on Magento -> Payment captured for the total order amount -> Magento order created -> Order sliced into per-seller sub-orders via the Integration Layer -> Sent to Sellaxis.
- **Catalogue & Pricing Flow:** 500,000 seller offers are ingested from Sellaxis and mapped to the 180k Magento base products. The Integration Layer updates pricing on Magento products based on the winning offer/Buy Box algorithm.
- **Inventory Flow:** Continuous incremental `inventory.changed` events + nightly full snapshots arrive from Sellaxis -> Idempotency/Version check -> Magento Multi-Source Inventory (MSI) salable quantities updated.
- **Shipment Flow:** Seller marks line as shipped in Sellaxis -> `order.line.shipped` webhook -> Integration layer creates Magento Shipment, retrieves tracking from ShipBridge -> Notifies buyer.
- **Cancellation & Refund Flow:** `order.cancelled` received from Sellaxis. If line is shipped -> Quarantine for manual RMA. If not shipped -> Release MSI reservation -> Generate partial Magento Credit Memo -> Refund customer via gateway.
- **Settlement Flow:** Settlement must be 100% accurate and finish by 00:30 daily. Nightly Cron exports shipped order lines + captured funds to the ERP. ERP ingests payment gateway settlement files, computes line totals minus Forgeline commission, and issues payouts to the 2,000 sellers in EUR or INR.

## 3. Multi-seller Order Splitting & State Machine
**Order Splitting Strategy:**
Magento creates a single monolithic `sales_order` during checkout to ensure payment capture happens securely in one transaction. However, the Integration Layer immediately splits the fulfillment routing. It maintains a mapping of Magento Order Items to Sellaxis Seller Lines.
- **Line-level acceptance/refusal:** If a buyer order has 1.2 sellers on average, and Seller A accepts while Seller B refuses:
  - Seller A's items are invoiced (if not pre-captured) and progressed to fulfillment.
  - Seller B's items trigger an immediate Magento **Partial Credit Memo**, refunding the buyer for the rejected lines without impacting Seller A's fulfillment.
- **Out-of-Order Events:** To combat out-of-order webhooks (e.g., `shipped` before `accepted`), the state machine relies on a strict timestamp tracker (`last_event_occurred_at`). If an incoming event carries a timestamp older than the stored timestamp for that specific order line, the event is safely dropped. Impossible forward state jumps (e.g. `shipped` before `created`) are queued to a "Hold" status for 5 minutes, allowing delayed prior events to arrive and resolve the dependency.

## 4. Async Processing, Idempotency & Reconciliation

**Messaging Flow:**
```text
   Incoming Webhook
          │
          ▼
  ┌──────────────────┐    No     ┌──────────────────┐
  │ Valid Signature? │ ────────> │ 401 Unauthorized │
  └──────────────────┘           └──────────────────┘
          │ Yes
          ▼
  ┌────────────────────────────────────────────────────┐
  │ 1. Write Unique Payload to MySQL Events Table      │
  │ 2. Publish Message Token to RabbitMQ               │
  │ 3. Return 202 Accepted Response to Sellaxis        │
  └────────────────────────┬───────────────────────────┘
                           │
                           ▼
                 ┌──────────────────┐
                 │ RabbitMQ Consumer│
                 └──────────────────┘
```

**Queues & Consumers:**
To meet the `< 30s P99` order acknowledgment SLA, the `POST /V1/sellaxis/webhook` endpoint performs **zero business logic**. It only:
1. Validates the HMAC signature.
2. Persists the payload to the `forgeline_sellaxis_events` table (with a UNIQUE index on `event_id` to guarantee **Idempotency**).
3. Publishes to Magento's RabbitMQ AMQP exchange and returns `200 OK` (typically ~50ms).

**Retries & Dead-Letter Handling:**
- Consumers process the RabbitMQ messages. If processing fails (e.g., missing SKU mapping), the message is routed to a Dead Letter Queue (DLQ).
- The DLQ consumer flags the record as `quarantined` in the DB for manual operator review via the Magento Admin panel. It is not blindly retried to avoid poison-pill loops.

**Reconciliation:**
- **Short-term Sweeps:** A cron job runs every 15 minutes, sweeping the events table for records stuck in `pending` or `processing` states for >10 minutes, generating alerts for stuck queues.
- **Automated Daily Reconciliation Engine:** To catch any edge-case synchronization issues, a deep audit cron runs daily at 02:00 UTC. It queries Sellaxis for all items updated within the last 24 hours and compares those metrics against Magento’s local tables. Any discrepancies log an explicit alert to the engineering team's dashboard.

## 5. Inventory Sync
**Overselling Prevention Strategy:**
Sellaxis inventory arrives as an absolute quantity with an integer `version`. We utilize Magento Multi-Source Inventory (MSI).
1. We extend the MSI `inventory_source_item` table with a custom `sellaxis_version` column.
2. Upon receiving an `inventory.changed` webhook (incremental or nightly full snapshot), the consumer fetches the current `sellaxis_version` from the DB.
3. `if (incoming_version <= current_version) { drop_silently; }` -> This gracefully handles batched out-of-order updates and prevents a stale nightly snapshot from overwriting a fresh incremental update.
4. If `incoming_version > current_version`, we update the `quantity` and the `sellaxis_version`.

## 6. Storage & Schema Sketch
**Core Entities:**
- `sales_order` / `sales_order_item`: Magento core truth for buyer orders.
- `inventory_source_item`: Extended with `sellaxis_version`.
**Custom Storage (MySQL):**
- `forgeline_sellaxis_events`: `entity_id`, `event_id` (VARCHAR 255, UNIQUE), `status` (ENUM), `occurred_at` (TIMESTAMP), `payload` (JSON). -> *Used for strict webhook idempotency.*
- `forgeline_seller`: `seller_id` (PK), `name`, `market` (IN/DE), `currency` (INR/EUR), `commission_rate`. -> *Used for settlement calculations.*
- `forgeline_offer_mapping`: `offer_id` (PK), `seller_id` (FK), `seller_sku`, `magento_product_id` (FK to simple product). -> *The crucial Offer <-> Product mapping layer.*
**Elasticsearch/OpenSearch:** Serves the B2B catalog frontend.
**Redis:** Session, Cache, and FPC.

## 7. Security and Seller/Tenant Isolation

**Webhook Authentication Requirements:**
All incoming webhooks from Sellaxis must use asymmetric cryptographic verification. The platform rejects any incoming payloads that lack a valid signature.
```php
public function validateWebhookRequest(Request $request): bool 
{
    $providedSignature = $request->getHeader('X-Sellaxis-Signature');
    $payloadBytes = $request->getContent();
    
    return openssl_verify(
        $payloadBytes, 
        base64_decode($providedSignature), 
        $this->getSellaxisPublicKey(), 
        OPENSSL_ALGO_SHA256
    ) === 1;
}
```

**Tenant Isolation:**
- Sellers log into a distinct Magento portal or API.
- We implement a strict **ACL Interceptor Pattern**. Every API request fetching order or catalogue data requires a Bearer token. The interceptor extracts the `seller_id` from the token and injects a hard `WHERE seller_id = X` constraint into all database queries and collection loads. Cross-tenant leakage is prevented at the database abstraction layer, not the controller level.

## 8. Operations (Magento Open Source vs. Adobe Commerce Cloud)
**Deployment & Configuration Management:**
- **Open Source Baseline:** We use Capistrano/Deployer for symlink-based zero-downtime deployments. Configuration is managed via `app/etc/config.php` (checked into git) and environment variables for secrets.
- **Adobe Commerce Cloud (ACC) Differences:** ACC uses a read-only production filesystem. The `build` phase (`setup:di:compile`, `setup:static-content:deploy`) occurs in the CI/CD pipeline, and the `deploy` phase swaps the router. We rely on the `.magento.env.yaml` file for injecting secrets natively into ACC.
**Rollback Strategy:**
- **Open Source:** Revert the symlink to the previous release folder. 
- **ACC:** Rely on the ACC infrastructure snapshot restoration or perform a fast "roll-forward" Git revert, as manual symlink manipulation is impossible.
- *Schema Constraint:* Database migrations must be strictly **additive-only** to ensure rollbacks do not destroy data.

## 9. Scaling & Sizing Arithmetic

**Core Production Load Targets:**
- **Peak Orders:** 25,000/day. Average 1.2 sellers/order.
- **Events per Order:** ~5 (created, accepted, shipped, settled, etc.) = ~150,000 order webhooks/day.
- **Inventory Updates:** Estimated 5 updates per offer per day * 500,000 offers = 2,500,000 webhooks/day.
- **Total Throughput:** ~2.65 million webhooks/day = **~31 requests/second average.**
- **Peak Multiplier (4x):** ~125 requests/second peak webhook ingestion.

**Sizing Your Message Queue Workers:**
- A single-threaded Magento queue consumer takes an average of 40 milliseconds to process a standard webhook payload.
- We calculate the processing capacity of a single consumer running continuously for one second:
  `Capacity Per Worker = 1000 ms / 40 ms/msg = 25 messages per second`
- To handle a peak load of 125 webhooks per second without queue delays, we calculate the required number of parallel workers:
  `Required Active Workers = 125 msgs/sec / 25 msgs/sec/worker = 5 workers`
- *Infrastructure Decision*: We deploy **6 parallel background consumers** across the environment to handle peak traffic safely with headroom for unexpected spikes.

**Database Storage Growth Over Time:**
- Each new marketplace event record requires roughly 1 KB of storage space inside `forgeline_sellaxis_events`.
- `Daily Storage Increase = 2.65m events * 1 KB/event = ~2.65 GB per day`.
- *Infrastructure Decision*: To prevent a 1 TB annual table bloat that would degrade performance, we will implement an aggressive Cron job to archive/purge completed events older than 14 days, maintaining a flat rolling DB size of ~37 GB.

## 10. Observability
To explicitly prove we meet the SLAs:
- **Order Acknowledgment (< 30s P99):** We track the time from Magento order placement to the successful ERP dispatch. Datadog APM tracing will measure this span.
- **Inventory Target (< 2 min P99):** We monitor the RabbitMQ queue depth `sellaxis.webhook.process`. If the queue depth exceeds 2,500 messages (roughly 20 seconds of processing backlog), a PagerDuty alert fires.
- **Stuck Orders:** A custom Datadog metric queries `forgeline_sellaxis_events` for `status = 'pending'` older than 3 minutes.
- **Settlement Target (by 00:30):** The Settlement Cron job wraps its execution in a Datadog monitor. If it has not emitted a `success` metric by 00:20, a critical alert is triggered.

## 11. Delivery Plan
- **Phase 1: Foundation (Weeks 1-3)**
  - Webhook ingestion endpoint, HMAC validation, Event DB table, RabbitMQ setup.
- **Phase 2: Catalogue & Inventory Sync (Weeks 4-6)**
  - `forgeline_offer_mapping` implementation, MSI versioning extension, queue consumers for inventory streams.
- **Phase 3: Order Orchestration & Splitting (Weeks 7-9)**
  - State machine, handling accept/refuse, generating partial Credit Memos, shipment integration.
- **Phase 4: Finance & Settlement (Weeks 10-12)**
  - ERP Purchase Order dispatches, nightly settlement cron exports.

**Explicit "Not Now" Decisions:**
1. **Automated Complex RMA Routing:** If an item is cancelled *after* shipment, the system will quarantine it and alert Customer Service. We will not build an automated return label flow via ShipBridge in V1.
2. **Real-time FX Conversion:** We do not compute live EUR/INR conversions. The storefront transacts in the target currency, and the ERP handles eventual settlement accounting.

## 12. Decision Log
**Decision 1: Splitting Fulfillment vs. Splitting Orders**
- *Options weighed:* Generate multiple Magento `sales_order` records at checkout (one per seller) vs. Generate a single Order and split Invoices/Shipments.
- *Decision:* **Single Order, Split Invoices.**
- *Reasoning:* Creating multiple Magento orders at checkout massively complicates the payment capture step (e.g., trying to capture one Stripe intent across multiple independent order objects). By using one order, we secure the funds cleanly, then use Partial Invoices to route funds and track seller fulfillment independently.

**Decision 2: Queueing Strategy for Webhooks**
- *Options weighed:* Process synchronously vs. Queue asynchronously.
- *Decision:* **Strictly Asynchronous.**
- *Reasoning:* To meet the < 30s P99 SLA and survive 125 req/sec traffic spikes, we cannot perform database writes or external API calls inside the HTTP request. The endpoint strictly saves the payload and queues it, immediately returning 200 OK.

**Decision 3: Dealing with Unmapped SKUs (Missing Seller-Product Mapping)**
- *Options weighed:* Drop the event vs. 500 Error vs. Quarantine.
- *Decision:* **Quarantine.**
- *Reasoning:* Returning a 500 error causes Sellaxis to endlessly retry, poisoning the queue. Dropping it silently loses the seller's order. We acknowledge the webhook (200 OK), save it, but place the Magento order in a custom "Mapping Exception" status, alerting an operator to fix the catalog mapping. Once mapped, a manual "Re-process" button retries the event.

**What we will NOT build, and why:**
We will *not* build an interactive frontend Seller Portal in Magento for catalog management. Building UI is expensive and brittle. We will rely on Sellaxis as the source of truth for seller catalog data and build robust headless API integrations/CSV importers to pull that data into Magento's backend mapping tables.
