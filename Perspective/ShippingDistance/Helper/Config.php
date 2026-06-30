<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Perspective\ShippingDistance\Model\Config as ModuleConfig;

/**
 * Helper used to pass config values into jsLayout via layout XML helpers.
 */
class Config extends AbstractHelper
{
    public function __construct(
        Context $context,
        private readonly ModuleConfig $moduleConfig
    ) {
        parent::__construct($context);
    }

    /**
     * Returns the Google API key safe for use in frontend JS (still relies on admin having set a key).
     * NOTE: The key is exposed to the browser only for Maps JS API / Places API usage.
     */
    public function getGoogleApiKeyForJs(): string
    {
        return $this->moduleConfig->getGoogleApiKey();
    }

    public function getGoogleMapId(): string
    {
        return $this->moduleConfig->getGoogleMapId();
    }

    public function getStoreLat(): float
    {
        return $this->moduleConfig->getStoreLat();
    }

    public function getStoreLng(): float
    {
        return $this->moduleConfig->getStoreLng();
    }
}
