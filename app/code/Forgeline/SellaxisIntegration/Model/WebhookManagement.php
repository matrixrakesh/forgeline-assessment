<?php
/**
 * WebhookManagement Model
 * Entry point for Sellaxis webhooks. Handles batch payloads and enforces idempotency.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Forgeline\SellaxisIntegration\Api\WebhookManagementInterface;
use Magento\Framework\Webapi\Rest\Request;
use Psr\Log\LoggerInterface;
use Forgeline\SellaxisIntegration\Model\EventFactory;
use Forgeline\SellaxisIntegration\Model\ResourceModel\Event as EventResource;
use Forgeline\SellaxisIntegration\Model\EventProcessor;

class WebhookManagement implements WebhookManagementInterface
{
    protected $request;
    protected $logger;
    protected $eventFactory;
    protected $eventResource;
    protected $eventProcessor;

    /**
     * Constructor
     *
     * @param Request $request
     * @param LoggerInterface $logger
     * @param EventFactory $eventFactory
     * @param EventResource $eventResource
     * @param EventProcessor $eventProcessor
     */
    public function __construct(
        Request $request,
        LoggerInterface $logger,
        EventFactory $eventFactory,
        EventResource $eventResource,
        EventProcessor $eventProcessor
    ) {
        $this->request = $request;
        $this->logger = $logger;
        $this->eventFactory = $eventFactory;
        $this->eventResource = $eventResource;
        $this->eventProcessor = $eventProcessor;
    }

    /**
     * Process incoming webhook payload.
     *
     * @return string
     * @throws \Exception
     */
    public function processWebhook()
    {
        // 1. Get raw body
        $body = $this->request->getContent();
        
        // Note: HMAC validation should happen here before trusting the body.
        
        $payload = json_decode($body, true);
        
        if (!$payload) {
            $this->logger->error('Invalid JSON payload from Sellaxis.');
            return 'Invalid JSON';
        }

        // Handle Array of Orders (Case 10)
        if (isset($payload['orders']) && is_array($payload['orders'])) {
            foreach ($payload['orders'] as $orderData) {
                try {
                    $this->eventProcessor->processOrderBatch($orderData);
                } catch (\Exception $e) {
                    // Case 10: Malformed item inside valid batch. 
                    // Log/quarantine the bad item, but do NOT fail the whole batch.
                    $this->logger->error('Failed processing order item in batch: ' . $e->getMessage());
                }
            }
            return 'Processed';
        }

        // Standard Event Payload
        $eventId = $payload['event_id'] ?? null;
        $deliveryId = $payload['delivery_id'] ?? null;
        $eventType = $payload['event_type'] ?? null;
        
        if (!$eventId) {
            return 'Missing event_id';
        }

        // Case 2 & Case 13: Idempotency Check
        // If event_id exists, we have already processed it successfully. Return 200 OK.
        $eventModel = $this->eventFactory->create();
        $this->eventResource->load($eventModel, $eventId, 'event_id');
        
        if ($eventModel->getId() && $eventModel->getStatus() === 'completed') {
            $this->logger->info("Duplicate webhook received and ignored. Event ID: {$eventId}");
            return 'OK'; // Acknowledge without side effects
        }

        try {
            // Save event as pending
            if (!$eventModel->getId()) {
                $eventModel->setEventId($eventId);
                $eventModel->setDeliveryId($deliveryId);
                $eventModel->setStatus('pending');
                $eventModel->setOccurredAt($payload['occurred_at'] ?? null);
                $this->eventResource->save($eventModel);
            }

            // Case 1 & Case 3 (Out-of-order) handled in Processor
            $this->eventProcessor->processEvent($payload);

            // Mark as completed
            $eventModel->setStatus('completed');
            $this->eventResource->save($eventModel);

            return 'OK';
        } catch (\Exception $e) {
            $this->logger->error("Error processing Sellaxis event {$eventId}: " . $e->getMessage());
            $eventModel->setStatus('failed');
            $this->eventResource->save($eventModel);
            throw $e; // Throwing will cause 500 and Sellaxis to retry
        }
    }
}
