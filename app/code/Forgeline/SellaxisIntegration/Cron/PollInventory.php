<?php
/**
 * PollInventory Cron
 * Pulls inventory updates from Sellaxis API.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class PollInventory
{
    protected $logger;
    protected $configReader;
    protected $configWriter;
    protected $baseUrl = 'https://api.sellaxis.test';

    const CONFIG_PATH_LAST_CURSOR = 'forgeline_sellaxis/polling/last_inventory_cursor';

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $configReader
     * @param WriterInterface $configWriter
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $configReader,
        WriterInterface $configWriter
    ) {
        $this->logger = $logger;
        $this->configReader = $configReader;
        $this->configWriter = $configWriter;
    }

    /**
     * Execute cron job.
     *
     * @return void
     */
    public function execute()
    {
        $this->logger->info("Starting Sellaxis Inventory Polling...");

        $cursor = $this->configReader->getValue(self::CONFIG_PATH_LAST_CURSOR) ?: date('c', strtotime('-1 day'));
        $page = 1;
        
        // Mock Seller IDs (in reality, query the forgeline_seller table)
        $sellerIds = ['SLR-1001', 'SLR-1002'];

        foreach ($sellerIds as $sellerId) {
            do {
                try {
                    $response = $this->fetchPage($sellerId, $cursor, $page);

                    if (isset($response['inventory']) && is_array($response['inventory'])) {
                        foreach ($response['inventory'] as $inventoryItem) {
                            try {
                                // Mocking the inventory manager
                                // $this->inventoryManager->processInventoryUpdate($inventoryItem);
                            } catch (\Exception $e) {
                                $this->logger->error("Failed to process inventory item in poll batch: " . $e->getMessage());
                            }
                        }
                    }

                    // Update cursor
                    if (!empty($response['next_since'])) {
                        $cursor = $response['next_since'];
                        $this->configWriter->save(self::CONFIG_PATH_LAST_CURSOR, $cursor);
                    }

                    $page = $response['next_page'] ?? null;

                } catch (\Exception $e) {
                    if ($e->getCode() === 429) {
                        $this->logger->warning("Sellaxis API Rate Limit Reached (429). Halting polling for all sellers until next cron execution.");
                        break 2; // Break out of both the do-while and foreach
                    }
                    
                    if ($e->getCode() === 500) {
                        $this->logger->error("Sellaxis API 500 Error. Halting polling for this seller, moving to next.");
                        break; // Break the do-while, continue to next seller
                    }

                    $this->logger->error("Unexpected polling error: " . $e->getMessage());
                    break;
                }

            } while ($page !== null);
        }

        $this->logger->info("Finished Sellaxis Inventory Polling.");
    }

    /**
     * Fetch a paginated chunk from Sellaxis API.
     *
     * @param string $sellerId
     * @param string $since
     * @param int $page
     * @return array
     * @throws \Exception
     */
    protected function fetchPage(string $sellerId, string $since, int $page): array
    {
        $endpoint = "/api/offers/inventory?seller_id={$sellerId}&since={$since}&page={$page}";
        $url = $this->baseUrl . $endpoint;
        
        $this->logger->info("GET {$url}");
        
        return [
            'inventory' => [],
            'next_page' => null,
            'next_since' => date('c')
        ];
    }
}
