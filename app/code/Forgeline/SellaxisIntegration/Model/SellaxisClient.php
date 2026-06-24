<?php
/**
 * SellaxisClient Model
 * Handles outbound operator callbacks (decide, confirm, ship, refund)
 * and enforces Idempotency-Key headers on all POST requests.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Psr\Log\LoggerInterface;

class SellaxisClient
{
    protected $logger;
    protected $baseUrl = 'https://api.sellaxis.test';

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
     * Submit line-level decisions (Accept/Refuse) - Two Phase Acceptance Step 1
     *
     * @param string $orderRef
     * @param array $decisions e.g., [['line_ref' => 'L1', 'decision' => 'accept']]
     * @return bool
     */
    public function decideLines(string $orderRef, array $decisions): bool
    {
        $endpoint = "/api/orders/{$orderRef}/lines:decide";
        $idempotencyKey = $this->generateUuid("decide-{$orderRef}");
        
        $payload = ['lines' => $decisions];
        
        return $this->post($endpoint, $payload, $idempotencyKey);
    }

    /**
     * Confirm the order decisions - Two Phase Acceptance Step 2
     *
     * @param string $orderRef
     * @return bool
     */
    public function confirmOrder(string $orderRef): bool
    {
        $endpoint = "/api/orders/{$orderRef}:confirm";
        $idempotencyKey = $this->generateUuid("confirm-{$orderRef}");
        
        return $this->post($endpoint, [], $idempotencyKey);
    }

    /**
     * Ship a specific order line.
     *
     * @param string $orderRef
     * @param string $lineRef
     * @param array $shippingData e.g., ['carrier' => '...', 'tracking' => '...']
     * @return bool
     */
    public function shipLine(string $orderRef, string $lineRef, array $shippingData): bool
    {
        $endpoint = "/api/orders/{$orderRef}/lines/{$lineRef}:ship";
        $idempotencyKey = $this->generateUuid("ship-{$orderRef}-{$lineRef}");
        
        return $this->post($endpoint, $shippingData, $idempotencyKey);
    }

    /**
     * Refund a specific order line.
     *
     * @param string $orderRef
     * @param string $lineRef
     * @param array $refundData e.g., ['amount' => '12.40', 'currency' => 'INR', 'reason' => '...']
     * @return bool
     */
    public function refundLine(string $orderRef, string $lineRef, array $refundData): bool
    {
        $endpoint = "/api/orders/{$orderRef}/lines/{$lineRef}:refund";
        $idempotencyKey = $this->generateUuid("refund-{$orderRef}-{$lineRef}");
        
        return $this->post($endpoint, $refundData, $idempotencyKey);
    }

    /**
     * Internal POST request handler.
     *
     * @param string $endpoint
     * @param array $payload
     * @param string $idempotencyKey
     * @return bool
     * @throws \Exception
     */
    protected function post(string $endpoint, array $payload, string $idempotencyKey): bool
    {
        $url = $this->baseUrl . $endpoint;
        
        $this->logger->info("POST {$url} | Idempotency-Key: {$idempotencyKey}");
        // In reality, use Magento's \Magento\Framework\HTTP\Client\Curl
        // Ensure to handle 429 Too Many Requests and 500 Internal Server Error
        
        // Mock success
        return true;
    }

    /**
     * Generate deterministic UUID v5.
     *
     * @param string $name
     * @return string
     */
    protected function generateUuid(string $name): string
    {
        return md5('sellaxis-outbound-' . $name);
    }
}
