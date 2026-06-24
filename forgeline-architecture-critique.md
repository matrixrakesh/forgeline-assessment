# Forgeline Architecture Critique (Part B)

This document provides a detailed critique of the `forgeline-reference-architecture-DRAFT.md`. The draft architecture contains catastrophic flaws that violate production SLAs, security standards, and operational constraints for Adobe Commerce Cloud.

## 1. Operations & Deployment (Fatal Flaw)

> [!CAUTION]
> **Flaw:** The draft suggests SSHing into the production node to run `git pull`, `composer install`, and `setup:upgrade` for deployments and rollbacks.

**Correction:**
This is physically impossible on Adobe Commerce Cloud (ACC). ACC uses a strictly **read-only production filesystem**. You cannot run `git pull` or `composer install` on a production node.
- **Proper Architecture:** Deployments must be executed via the CI/CD pipeline, where the `build` phase (`setup:di:compile`, static deployment) happens on a build server, and the `deploy` phase swaps the read-only mounted volume.
- **Rollback:** Rollbacks cannot use `git revert` directly on the server. They must rely on ACC snapshot restoration or a "roll-forward" Git revert pushed through the standard CI pipeline. Database schemas must be strictly additive-only to survive rollbacks.

## 2. Ingestion & Deduplication (Data Loss)

> [!WARNING]
> **Flaw:** Deduping on `delivery_id` using a volatile Redis set.

**Correction:**
- **Wrong Key:** The Sellaxis stubs explicitly state that duplicates can arrive with *different* `delivery_ids`. If we dedup on `delivery_id`, the duplicate order will slip through, causing double fulfillment. We must dedup strictly on the **`event_id`**.
- **Volatile Storage:** Redis is an in-memory cache. If Redis restarts or evicts keys under memory pressure, the dedup state is lost. 
- **Proper Architecture:** Idempotency must be guaranteed by a `UNIQUE` index on an `event_id` column inside a persistent MySQL table (e.g., `forgeline_sellaxis_events`).

## 3. Inventory Sync (Overselling Guarantee)

> [!WARNING]
> **Flaw:** Directly overwriting `StockRegistryInterface` with `available_qty` and ignoring the Sellaxis `version` field.

**Correction:**
- **Ignoring Versioning:** Webhooks arrive out-of-order, and batched. A nightly snapshot (run at 01:00) might contain older data than an incremental update that arrived at 00:59. Blindly overwriting stock guarantees that fresh incremental updates will be destroyed by stale nightly snapshots, leading to massive overselling.
- **Ignoring MSI:** Directly updating stock bypasses Magento's Multi-Source Inventory (MSI) reservations. This creates race conditions where stock is updated while a buyer is in the middle of checkout.
- **Proper Architecture:** We must extend `inventory_source_item` with a `sellaxis_version` integer. All consumers must check: `if (incoming_version <= current_version) { drop; }`.

## 4. Order Orchestration & Finance

> [!WARNING]
> **Flaw:** Synchronous POST to ERP on order creation, with up to 5 blocking retries. 

**Correction:**
- **Blocking the Queue:** A synchronous HTTP retry loop blocks the RabbitMQ consumer. With a 30s P99 SLA, blocking a consumer for 5 timeouts will cause the queue to back up instantly, missing the SLA across the board.
- **Duplicate POs:** Retrying an ERP POST without an `Idempotency-Key` header guarantees that if the ERP actually committed the order but timed out on the response (504), the retry will create a duplicate Purchase Order.
- **Manual Payment Reconciliation:** The draft states that if order creation fails after payment capture, "finance can reconcile manually." At 25,000 orders/day, this is unacceptable and illegal. We must use an automated Orphaned Capture outbox to immediately void/refund payments without orders.
- **Settlement Rounding:** Summing line totals and applying commission/currency rounding *at the end* will create precision drift. Rounding must happen per-line.

## 5. Security (IDOR Vulnerability)

> [!CAUTION]
> **Flaw:** The seller dashboard filters orders using a frontend SPA query parameter: `GET /api/orders?seller_id=123`.

**Correction:**
- This is a textbook **Insecure Direct Object Reference (IDOR)** vulnerability. Any authenticated seller can simply change the URL to `?seller_id=999` and view a competitor's orders.
- **Proper Architecture:** We must use an **ACL Interceptor**. The server must extract the `seller_id` securely from the authenticated Bearer token and forcefully inject `WHERE seller_id = X` at the repository/database layer. Never trust client-provided IDs for data isolation.

## 6. Observability & SLAs

> [!NOTE]
> **Flaw:** Monitoring CPU/RAM to determine integration health.

**Correction:**
- Host metrics (CPU/RAM) are useless for determining if we are meeting a 30s P99 business SLA. A server can have 5% CPU utilization while thousands of messages are stuck in a dead-letter queue.
- **Proper Architecture:** We must use APM Tracing (e.g., Datadog) to track end-to-end spans (from webhook ingestion to ERP dispatch). We must set up PagerDuty alerts tied specifically to RabbitMQ queue depth and `pending` rows in the events table older than 3 minutes.

## 7. Infrastructure SPOFs & RPO 0 Claims

> [!CAUTION]
> **Flaw:** Using a single Redis instance for FPC, sessions, queues, and dedup. Claiming RPO 0 with an asynchronous MySQL Read Replica.

**Correction:**
- **Redis Eviction:** Mixing volatile cache (FPC) with persistent session/queue data on a single Redis instance is highly dangerous. If Redis hits `maxmemory`, it will begin evicting keys based on its policy, potentially destroying active shopping carts or deduplication states. Queues must run on RabbitMQ, and Redis must be split into dedicated instances for Session vs Cache.
- **Impossible RPO 0:** An asynchronous MySQL read replica always has replication lag. If the primary instance dies unexpectedly, the last few milliseconds of transactions are lost. You cannot mathematically claim an RPO of 0 (Zero Data Loss) with async replication. It requires synchronous replication (e.g., Galera cluster or Aurora multi-AZ).

## 8. Security (Unauthenticated Webhooks)

> [!CAUTION]
> **Flaw:** The draft states "The webhook endpoint is public so Sellaxis can reach it" with no mention of authentication.

**Correction:**
- Leaving a webhook endpoint completely unprotected allows malicious actors to blindly POST fake JSON payloads, injecting fraudulent orders or manipulating competitor inventory.
- **Proper Architecture:** The integration must enforce strictly cryptographic signature verification using the `X-Sellaxis-Signature` header and `hash_equals()` to prevent timing attacks. Any unauthenticated requests must be immediately rejected with HTTP 401.
