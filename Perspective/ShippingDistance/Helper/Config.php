<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Perspective\ShippingDistance\Model\Config as ModuleConfig;

/**
 * Passes config values into jsLayout via layout XML helpers.
 *
 * ONE-KEY NOTE
 * ────────────────────────────────────────────────────────────────────────
 * The same Google Maps API key is used for both the frontend Maps JS SDK
 * and the backend Distance Matrix API call. Exposing it here in jsLayout
 * is intentional — the Maps JS SDK requires the key to be in the browser.
 *
 * Security comes from restricting the key by HTTP Referrer in Google Cloud
 * Console (not by IP). The server-side DistanceCalculator fakes the same
 * Referer header so the key works from PHP too.
 * ────────────────────────────────────────────────────────────────────────
 */
class Config extends AbstractHelper
{
    public function __construct(
        Context $context,
        private readonly ModuleConfig $moduleConfig
    ) {
        parent::__construct($context);
    }

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
