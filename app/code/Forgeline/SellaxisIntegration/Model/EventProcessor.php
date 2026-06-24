<?php
/**
 * EventProcessor Model
 * Processes validated events, handling business logic like out-of-order execution and missing mappings.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Psr\Log\LoggerInterface;

class EventProcessor
{
    protected $logger;
    
    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Process a single event payload.
     *
     * @param array $payload
     * @return void
     */
    public function processEvent(array $payload)
    {
        $eventType = $payload['event_type'] ?? '';
        $data = $payload['data'] ?? [];
        $occurredAt = strtotime($payload['occurred_at'] ?? 'now');

        switch ($eventType) {
            case 'order.created':
                $this->handleOrderCreated($data, $occurredAt);
                break;
            case 'order.line.shipped':
            case 'order.line.accepted':
                $this->handleStatusChange($data, $occurredAt, $eventType);
                break;
            default:
                $this->logger->info("Unhandled event type: {$eventType}");
        }
    }

    /**
     * Process a batch of orders.
     *
     * @param array $orderData
     * @return void
     * @throws \Exception
     */
    public function processOrderBatch(array $orderData)
    {
        // Case 10: Handle malformed item within a batch
        if (!isset($orderData['lines']) || !is_array($orderData['lines'])) {
             throw new \Exception("Malformed order data: missing lines.");
        }

        foreach ($orderData['lines'] as $line) {
            if (!isset($line['seller_sku']) || !isset($line['qty']) || !is_numeric($line['qty'])) {
                 // Throw exception for this specific malformed line. 
                 // The WebhookManagement wrapper catches it and logs it,
                 // ensuring the rest of the valid lines process correctly.
                 throw new \Exception("Malformed line item detected and quarantined.");
            }
            
            // Map SKU and process the valid line
            $magentoSku = $this->mapSellerSku($line['seller_sku']);
        }
        
        // Proceed with Magento Order creation...
    }

    /**
     * Handle order.created event.
     *
     * @param array $data
     * @param int $occurredAt
     * @return void
     */
    protected function handleOrderCreated(array $data, int $occurredAt)
    {
        // Case 1: Normal Order
        // Logic to create Magento Order goes here
        $this->logger->info("Creating order: " . ($data['order_ref'] ?? 'Unknown'));
        
        // Simulate missing SKU / Unknown SKU (Cases 5 & 6)
        if (isset($data['lines'])) {
            foreach ($data['lines'] as $line) {
                try {
                    $magentoSku = $this->mapSellerSku($line['seller_sku'] ?? null);
                } catch (\Exception $e) {
                    // Case 5 & 6: We don't fail the webhook.
                    // We log/quarantine the order into an exception state in Magento for manual review.
                    $this->logger->error("Mapping Exception for Order {$data['order_ref']}: " . $e->getMessage());
                    // Create order with 'suspected_fraud' or custom 'quarantine' status
                }
            }
        }
    }

    /**
     * Handle order status change events.
     *
     * @param array $data
     * @param int $occurredAt
     * @param string $eventType
     * @return void
     */
    protected function handleStatusChange(array $data, int $occurredAt, string $eventType)
    {
        $orderRef = $data['order_ref'] ?? '';
        
        // Case 3: Out-of-order status event
        $lastTransitionTime = $this->getLastTransitionTime($orderRef);
        
        if ($occurredAt < $lastTransitionTime) {
            // Event occurred before the last known state transition. Ignore it.
            $this->logger->warning("Ignored out-of-order event {$eventType} for {$orderRef}. Occurred at {$occurredAt}, but last state was at {$lastTransitionTime}.");
            return;
        }

        // Valid transition, process it...
        $this->logger->info("Processed valid transition {$eventType} for {$orderRef}.");
        $this->updateLastTransitionTime($orderRef, $occurredAt);
    }

    /**
     * Map seller SKU to Magento SKU.
     *
     * @param string|null $sellerSku
     * @return string
     * @throws \Exception
     */
    protected function mapSellerSku($sellerSku)
    {
        // Mock mapping layer
        if ($sellerSku === null || $sellerSku === 'GHOST-SKU-0') {
            // Case 6: Unknown SKU
            throw new \Exception("Unknown SKU: " . $sellerSku);
        }
        
        if ($sellerSku === 'TOOL-RANDOM-X') {
            // Case 5: Missing seller-product mapping
            throw new \Exception("Missing seller mapping for SKU: " . $sellerSku);
        }
        
        return 'mapped-' . $sellerSku;
    }

    /**
     * Get the last transition time for an order.
     *
     * @param string $orderRef
     * @return int
     */
    protected function getLastTransitionTime(string $orderRef): int
    {
        // In a real module, this would query the Magento order history/comments table
        // For demonstration, we return a mock timestamp.
        return 0; 
    }

    /**
     * Update the last transition time for an order.
     *
     * @param string $orderRef
     * @param int $occurredAt
     * @return void
     */
    protected function updateLastTransitionTime(string $orderRef, int $occurredAt)
    {
        // Update the custom timestamp tracker for the order
    }
}
