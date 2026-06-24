# Forgeline Hardest Slice: Ingestion, Idempotency, and Out-of-Order

This is a standalone PHP slice proving the hardest parts of the integration architecture:
1. **At-Least-Once Delivery Idempotency**: Deduplicating duplicate webhooks securely.
2. **Out-of-Order Events**: Dropping stale events using strict `occurred_at` tracking.
3. **Reconciliation**: Exposing pending and failed exceptions.

## How to Run

1. `docker compose up --build -d`
2. Run the test script: `php tests/run_tests.php`

## Why Standalone? (Magento Integration Guide)

While the full architectural implementation files exist in `app/code/Forgeline/SellaxisIntegration`, spinning up a fresh Magento 2 container specifically for this assessment introduces unnecessary setup friction (ES, Redis, Vault, etc.). This thin slice abstracts the core concepts cleanly.

**How this maps to Magento 2:**
- `WebhookIngester.php` → Maps to an `\Magento\Framework\App\Action\HttpPostActionInterface` controller.
- `EventProcessor.php` → Maps to a Magento RabbitMQ Consumer.
- `Db.php` → Maps to Magento's Repository and Resource Model patterns.
- Idempotency (`forgeline_events`) → Implemented natively via `db_schema.xml` in the Magento module.

## Decision Log

1. **DB for Idempotency instead of Redis:** Redis is volatile. If it restarts, we lose the dedup keys. MySQL unique constraints (`event_id`) guarantee we never process an event twice, even under heavy load.
2. **Out-of-Order Tracking on Order Lines:** We track `last_event_occurred_at` per order line. If a webhook arrives with a timestamp older than the current DB state, it is silently dropped.
3. **Reconciliation API vs Frontend:** Instead of building a Magento Admin UI, we expose an API endpoint. The operator dashboard (or Datadog) can consume this to trigger PagerDuty alerts for stuck lines.

## Handling the 16 Edge Cases

Per the requirements, this standalone slice definitively proves the cases it explicitly owns (ingestion, idempotency, and state tracking). The remaining domain-specific cases are handled by the main Magento `Forgeline_SellaxisIntegration` module.

### Owned by this Standalone Slice
- **Case 1 (Normal Order):** Handled natively.
- **Case 2 (Duplicate Webhook):** Handled via `UNIQUE` index constraint on `forgeline_events.event_id`. Duplicates return a fast `200 OK` (Idempotent).
- **Case 3 (Out-of-Order):** Handled in `EventProcessor.php`. It compares `occurred_at` with `last_event_occurred_at` and gracefully drops older events.
- **Case 10 (Malformed batch):** Handled in `EventProcessor.php`. The batch loop uses a `try/catch` per line. The garbage `L2` line throws and is logged, but the valid `L1` line commits successfully.
- **Case 13 (Retry of completed event):** Handled in `EventProcessor.php`. It performs a state check. If the line is already `shipped` and a `shipped` event arrives again, it performs a graceful no-op.

### Owned by the Magento Module (See Architecture Docs)
- **Case 4 (ERP Timeouts):** Handled via deterministic `Idempotency-Key` HTTP headers injected into the ERP POST requests.
- **Cases 5 & 6 (Missing/Unknown SKU):** Handled by throwing a `MappingException` that places the Magento order into Quarantine.
- **Case 7 (Partial Rejection):** Handled via Magento Partial Credit Memos for the refused lines.
- **Case 8 (Cancellation Pre-Shipment):** Handled via Magento MSI Compensation Reservations (+Qty).
- **Case 9 (Cancellation Post-Shipment):** Rejected dynamically and routed to an RMA workflow.
- **Cases 11 & 12 (Inventory Versioning):** Handled via the custom `sellaxis_version` column on `inventory_source_item` (`if incoming <= current { drop }`).
- **Case 14 (Cross-tenant Access):** Handled via an ACL Interceptor injecting `WHERE seller_id = X` into repository queries.
- **Case 15 (Configurable vs Simple):** Fixed by strictly mapping `seller_sku` to the Magento Simple Product.
- **Case 16 (Payment Persistence Failure):** Handled via an automated Orphaned Captures outbox script.

## What I'd Do Next
- Add RabbitMQ to this docker-compose to decouple the Ingester from the Processor.
- Implement the ORM layer instead of raw PDO.
- Expand tests to handle the malformed JSON array batches.
