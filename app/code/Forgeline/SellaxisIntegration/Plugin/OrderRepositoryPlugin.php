<?php
/**
 * OrderRepositoryPlugin
 * Enforces Tenant Isolation (Case 14). Ensures a seller cannot access another seller's order.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Plugin;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Psr\Log\LoggerInterface;

class OrderRepositoryPlugin
{
    protected $logger;
    protected $filterBuilder;
    protected $filterGroupBuilder;

    /**
     * Constructor
     */
    public function __construct(
        LoggerInterface $logger,
        FilterBuilder $filterBuilder,
        FilterGroupBuilder $filterGroupBuilder
    ) {
        $this->logger = $logger;
        $this->filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
    }

    /**
     * Intercept getList to enforce seller isolation.
     *
     * @param OrderRepositoryInterface $subject
     * @param SearchCriteriaInterface $searchCriteria
     * @return array
     */
    public function beforeGetList(
        OrderRepositoryInterface $subject,
        SearchCriteriaInterface $searchCriteria
    ) {
        // Case 14: Tenant Isolation.
        // Mocking the retrieval of the authenticated seller ID from the token/session.
        $authenticatedSellerId = $this->getAuthenticatedSellerId();

        if ($authenticatedSellerId) {
            $this->logger->info("Enforcing tenant isolation for seller {$authenticatedSellerId} on OrderRepository::getList");

            $filter = $this->filterBuilder
                ->setField('seller_id')
                ->setConditionType('eq')
                ->setValue($authenticatedSellerId)
                ->create();

            $filterGroup = $this->filterGroupBuilder
                ->addFilter($filter)
                ->create();

            $searchCriteria->setFilterGroups(array_merge($searchCriteria->getFilterGroups(), [$filterGroup]));
        }

        return [$searchCriteria];
    }

    /**
     * Intercept get to enforce seller isolation.
     *
     * @param OrderRepositoryInterface $subject
     * @param \Magento\Sales\Api\Data\OrderInterface $result
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function afterGet(
        OrderRepositoryInterface $subject,
        $result
    ) {
        $authenticatedSellerId = $this->getAuthenticatedSellerId();

        // In a real scenario, this checks the `sales_order` extension attributes or custom column.
        $orderSellerId = $result->getData('seller_id');

        if ($authenticatedSellerId && $orderSellerId && $authenticatedSellerId !== $orderSellerId) {
            $this->logger->warning("Cross-tenant access attempt detected! Seller {$authenticatedSellerId} tried to access order {$result->getIncrementId()}");
            throw new \Magento\Framework\Exception\NoSuchEntityException(__('The order that was requested doesn\'t exist.'));
        }

        return $result;
    }

    /**
     * Mock getting the authenticated seller ID.
     *
     * @return string|null
     */
    protected function getAuthenticatedSellerId()
    {
        // In a real module, extract from Authorization Bearer token Context.
        return 'SLR-1001'; 
    }
}
