<?php
/**
 * ErpClient Model
 * Communicates with the external ERP, injecting Idempotency-Key to handle timeouts safely.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Psr\Log\LoggerInterface;

class ErpClient
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
     * Dispatch Purchase Order to ERP (Case 4).
     *
     * @param array $orderData
     * @param string $orderIncrementId
     * @return void
     * @throws \Exception
     */
    public function dispatchPurchaseOrder(array $orderData, string $orderIncrementId)
    {
        // Case 4: ERP times out after success.
        // We MUST send an Idempotency-Key.
        // Generate a deterministic UUID based on the Magento Order Increment ID.
        $idempotencyKey = $this->generateUuidV5($orderIncrementId);

        $this->logger->info("Dispatching PO to ERP for Order {$orderIncrementId} with Idempotency-Key: {$idempotencyKey}");

        // Mock HTTP Client Call
        try {
            $this->simulateErpTimeout();
            $this->logger->info("ERP PO Dispatch Success.");
        } catch (\Exception $e) {
            $this->logger->error("ERP Connection Timeout. The RabbitMQ consumer will retry with the SAME Idempotency-Key: {$idempotencyKey}");
            // Throwing exception triggers RabbitMQ native retry
            throw $e;
        }
    }

    /**
     * Simulate an ERP 504 Timeout.
     *
     * @throws \Exception
     */
    protected function simulateErpTimeout()
    {
        // Simulating the exact scenario in Case 4.
        throw new \Exception("HTTP 504 Gateway Timeout from ERP");
    }

    /**
     * Generate deterministic UUID v5.
     *
     * @param string $name
     * @return string
     */
    protected function generateUuidV5(string $name): string
    {
        // Simple mock for demonstration
        return md5('erp-po-' . $name);
    }
}
