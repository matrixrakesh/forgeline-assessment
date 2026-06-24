<?php
/**
 * Module registration for Forgeline_SellaxisIntegration.
 * Handles the registration of the integration module within Magento 2.
 */
declare(strict_types=1);

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Forgeline_SellaxisIntegration',
    __DIR__
);
