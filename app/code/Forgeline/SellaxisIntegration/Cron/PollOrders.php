<?php
/**
 * PollOrders Cron
 * Pulls orders from Sellaxis API to reconcile lost webhooks.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

class PollOrders
{
    protected $logger;
    protected $configReader;
    protected $configWriter;
    protected $baseUrl = 'https://api.sellaxis.test';

    const CONFIG_PATH_LAST_CURSOR = 'forgeline_sellaxis/polling/last_order_cursor';

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
        $this->logger->info("Starting Sellaxis Order Polling...");

        $cursor = $this->configReader->getValue(self::CONFIG_PATH_LAST_CURSOR) ?: date('c', strtotime('-1 day'));
        $page = 1;

        do {
            try {
                $response = $this->fetchPage($cursor, $page);

                if (isset($response['orders']) && is_array($response['orders'])) {
                    foreach ($response['orders'] as $order) {
                        try {
                            // Mocking the event processor
                            // $this->eventProcessor->processOrderBatch($order);
                        } catch (\Exception $e) {
                            $this->logger->error("Failed to process order in poll batch: " . $e->getMessage());
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
                // If 429 Too Many Requests, break completely and wait for next cron run
                if ($e->getCode() === 429) {
                    $this->logger->warning("Sellaxis API Rate Limit Reached (429). Halting polling until next cron execution.");
                    break;
                }
                
                // If 500 Internal Server Error, log and break
                if ($e->getCode() === 500) {
                    $this->logger->error("Sellaxis API 500 Error. Halting polling.");
                    break;
                }

                $this->logger->error("Unexpected polling error: " . $e->getMessage());
                break;
            }

        } while ($page !== null);

        $this->logger->info("Finished Sellaxis Order Polling.");
    }

    /**
     * Fetch a paginated chunk from Sellaxis API.
     *
     * @param string $since
     * @param int $page
     * @return array
     * @throws \Exception
     */
    protected function fetchPage(string $since, int $page): array
    {
        $endpoint = "/api/orders?since={$since}&page={$page}";
        $url = $this->baseUrl . $endpoint;
        
        $this->logger->info("GET {$url}");
        
        // Mocking the HTTP Call...
        // In reality, if curl_getinfo($ch, CURLINFO_HTTP_CODE) == 429, throw new \Exception('Too Many Requests', 429);
        
        return [
            'orders' => [],
            'next_page' => null,
            'next_since' => date('c')
        ];
    }
}
