<?php
/**
 * Event Resource Model
 * Handles database operations for the forgeline_sellaxis_events table.
 */
declare(strict_types=1);

namespace Forgeline\SellaxisIntegration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Event extends AbstractDb
{
    /**
     * Initialize main table and primary key.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('forgeline_sellaxis_events', 'entity_id');
    }
}
