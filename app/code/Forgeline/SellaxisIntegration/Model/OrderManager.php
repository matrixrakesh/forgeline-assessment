<?php
/**
 * OrderManager Model
 * Handles complex state transitions like partial rejections, cancellations, and payment captures.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Psr\Log\LoggerInterface;

class OrderManager
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
     * Handle order.line.refused event (Case 7).
     *
     * @param string $orderRef
     * @param array $refusedLines
     * @return void
     */
    public function handlePartialRejection(string $orderRef, array $refusedLines)
    {
        // Case 7: One seller rejects some lines.
        // We do NOT stall the entire order. 
        // We create a Partial Credit Memo for the rejected lines.
        $this->logger->info("Processing partial rejection for {$orderRef}. Generating Partial Credit Memo for " . count($refusedLines) . " lines.");
        
        // Mocking the Magento CreditmemoFactory logic:
        foreach ($refusedLines as $line) {
            $this->logger->info("Refunding Line Ref: {$line['line_ref']} (SKU: {$line['seller_sku']})");
        }
    }

    /**
     * Handle order.cancelled event (Cases 8 & 9).
     *
     * @param string $orderRef
     * @param bool $hasShipments
     * @return void
     */
    public function handleCancellation(string $orderRef, bool $hasShipments)
    {
        if ($hasShipments) {
            // Case 9: Cancellation arriving after shipment creation.
            // Cannot un-ship. Must quarantine for RMA.
            $this->logger->warning("Cancellation received for {$orderRef} but it already has shipments. Routing to RMA workflow.");
            // $order->setStatus('rma_required');
        } else {
            // Case 8: Cancellation arriving after inventory reservation.
            // Release MSI reservation by applying a compensation.
            $this->logger->info("Cancellation received for {$orderRef}. Applying MSI reservation compensation (+qty).");
            // App\MSI\AppendReservationsInterface::execute(...)
        }
    }

    /**
     * Create order with payment validation (Case 16).
     *
     * @param array $orderData
     * @return void
     */
    public function placeOrderWithPaymentFallback(array $orderData)
    {
        // Case 16: Payment captured but order persistence failing.
        $paymentIntentId = 'pi_mock_123'; 
        
        try {
            // Assume payment was captured successfully at the gateway
            $this->logger->info("Payment captured: {$paymentIntentId}. Attempting to save Magento Order.");
            
            // Deliberately simulate an exception during order persistence
            throw new \Exception("Database lock timeout during sales_order save.");
        } catch (\Exception $e) {
            $this->logger->error("Order persistence failed for payment {$paymentIntentId}: " . $e->getMessage());
            
            // Publish to OrphanedCaptures queue to immediately void the payment
            $this->publishOrphanedCapture($paymentIntentId);
        }
    }

    /**
     * Publish orphaned capture for reconciliation.
     *
     * @param string $paymentIntentId
     * @return void
     */
    protected function publishOrphanedCapture(string $paymentIntentId)
    {
        $this->logger->info("Queued payment {$paymentIntentId} for immediate VOID/REFUND via Cron.");
    }
}
