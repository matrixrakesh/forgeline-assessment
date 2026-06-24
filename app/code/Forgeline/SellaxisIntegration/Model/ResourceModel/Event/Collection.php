<?php
/**
 * Event Collection
 * Collection class for the Event model.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model\ResourceModel\Event;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * Define resource model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Forgeline\SellaxisIntegration\Model\Event::class,
            \Forgeline\SellaxisIntegration\Model\ResourceModel\Event::class
        );
    }
}
