# Forgeline Sellaxis Integration (Magento 2 Module)

This directory contains the native Adobe Commerce / Magento 2 module representing the production implementation of the marketplace integration layer. 

While the assessment ("Part C") was executed as a Standalone PHP slice to eliminate setup friction and guarantee a 1-command runnable environment, this module is provided as a **bonus** to explicitly demonstrate how that vanilla PHP logic maps directly to Magento's internal framework.

## Architectural Mapping

### 1. Database Schema (`etc/db_schema.xml`)
We use Magento's Declarative Schema to safely provision the infrastructure.
- **`forgeline_sellaxis_events`**: The central idempotency table. It enforces an absolute `UNIQUE` constraint on `event_id` to guarantee mathematically secure deduplication (preventing the RPO failures associated with volatile Redis caches).
- **`sales_order_item` Extension**: We extend Magento's native sales order lines with a `last_event_occurred_at` column. This is crucial for the state machine to reject stale, out-of-order webhooks.
- **`inventory_source_item` Extension**: We inject a `sellaxis_version` column into MSI (Multi-Source Inventory) to safely drop stale batched inventory updates.

### 2. Dependency Injection & Security (`etc/di.xml`)
- **Webhook Controller**: We route the ingestion endpoints through `\Magento\Framework\App\Action\HttpPostActionInterface`.
- **ACL Interceptor Pattern**: To prevent the Insecure Direct Object Reference (IDOR) vulnerabilities flagged in the draft architecture, we inject `Forgeline\SellaxisIntegration\Plugin\OrderRepositoryPlugin`. This intercepts order queries and forcefully applies a `seller_id` filter based on the secure server-side Bearer token, guaranteeing strict tenant isolation.

### 3. Asynchronous Queueing
- Incoming webhooks are verified via HMAC `openssl_verify`, inserted into `forgeline_sellaxis_events`, and immediately dispatched to RabbitMQ via Magento's `PublisherInterface` to guarantee a `< 30s P99` ingestion SLA.
- The heavy state machine transitions (splitting orders, partial invoices) are handled safely in the background by Magento's Queue Consumers.

## Installation

Because this module utilizes standard Adobe Commerce architecture, it is deployed via standard Magento lifecycle commands:

```bash
# 1. Enable the module
bin/magento module:enable Forgeline_SellaxisIntegration

# 2. Run declarative schema updates (Additive Only)
bin/magento setup:upgrade

# 3. Compile Dependency Injection
bin/magento setup:di:compile

# 4. Start the RabbitMQ Consumer Daemons
bin/magento queue:consumers:start sellaxis.webhook.process &
```
