<?php
/**
 * InventoryManager Model
 * Handles incoming inventory events, ensuring no older version overwrites a newer version.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Psr\Log\LoggerInterface;

class InventoryManager
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
     * Process inventory.changed event
     *
     * @param array $payload
     * @return void
     */
    public function processInventoryUpdate(array $payload)
    {
        $offerId = $payload['offer_id'] ?? null;
        $incomingVersion = (int)($payload['version'] ?? 0);
        $availableQty = (int)($payload['available_qty'] ?? 0);
        $sellerSku = $payload['seller_sku'] ?? null;

        if (!$offerId) {
            $this->logger->error("Missing offer_id in inventory update payload.");
            return;
        }

        // Cases 11 & 12: Stale Inventory / Overlapping Full Sync
        $storedVersion = $this->getCurrentStoredVersion($offerId);
        
        if ($incomingVersion <= $storedVersion) {
            $this->logger->warning("Ignored stale inventory update for {$offerId}. Incoming version: {$incomingVersion}, Stored version: {$storedVersion}.");
            return;
        }

        // Case 15: Configurable vs Simple SKU mapping
        $magentoSimpleSku = $this->resolveSimpleProductSku($sellerSku);

        // Update Magento MSI Inventory (mocked)
        $this->logger->info("Updating inventory for {$magentoSimpleSku} (Offer: {$offerId}) to {$availableQty}. New version: {$incomingVersion}");
        
        // Emulate DB update...
        $this->updateStoredVersion($offerId, $incomingVersion);
    }

    /**
     * Get the currently stored sellaxis_version for the given offer_id.
     *
     * @param string $offerId
     * @return int
     */
    protected function getCurrentStoredVersion(string $offerId): int
    {
        // Mock query from `inventory_source_item` joined with `forgeline_offer_mapping`
        // In a real module, we query the `sellaxis_version` column.
        return 46; // Mock stored version
    }

    /**
     * Update the stored sellaxis_version.
     *
     * @param string $offerId
     * @param int $newVersion
     * @return void
     */
    protected function updateStoredVersion(string $offerId, int $newVersion)
    {
        // Update the `sellaxis_version` column in `inventory_source_item`
    }

    /**
     * Map seller SKU to a Magento Simple Product SKU.
     *
     * @param string $sellerSku
     * @return string
     * @throws \Exception
     */
    protected function resolveSimpleProductSku(string $sellerSku): string
    {
        // Must map to a simple product, not configurable.
        // E.g. 'SAFETY-GLOVE-L' -> 'frg-glove-nitrile-l'
        return 'frg-simple-' . $sellerSku;
    }
}
