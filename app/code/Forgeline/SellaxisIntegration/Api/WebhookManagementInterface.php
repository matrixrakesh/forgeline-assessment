<?php
/**
 * Interface WebhookManagementInterface
 * Defines the contract for processing incoming Sellaxis webhooks.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Api;

interface WebhookManagementInterface
{
    /**
     * Process incoming Sellaxis webhook
     *
     * @return string
     */
    public function processWebhook();
}
