<?php
/**
 * Event Model
 * Represents a single Sellaxis event to ensure idempotency.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model;

use Magento\Framework\Model\AbstractModel;

class Event extends AbstractModel
{
    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Forgeline\SellaxisIntegration\Model\ResourceModel\Event::class);
    }
}
