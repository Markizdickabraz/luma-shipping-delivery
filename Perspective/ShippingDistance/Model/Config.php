<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'shipping_distance/general/enabled';
    private const XML_PATH_GOOGLE_API_KEY        = 'shipping_distance/general/google_api_key';
    private const XML_PATH_GOOGLE_SERVER_API_KEY  = 'shipping_distance/general/google_server_api_key';
    private const XML_PATH_GOOGLE_MAP_ID          = 'shipping_distance/general/google_map_id';
    private const XML_PATH_STORE_LAT = 'shipping_distance/general/store_lat';
    private const XML_PATH_STORE_LNG = 'shipping_distance/general/store_lng';
    private const XML_PATH_PRICE_PER_KM = 'shipping_distance/general/price_per_km';
    private const XML_PATH_MAX_DISTANCE = 'shipping_distance/general/max_distance_km';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function isEnabled(?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    /**
     * Returns the decrypted Google Maps API key.
     * The value is stored encrypted in DB by the Encrypted backend model;
     * scopeConfig->getValue() returns the raw encrypted string, so we must decrypt it.
     */
    public function getGoogleApiKey(?string $scopeCode = null): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );

        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }

    /**
     * Returns the API key for server-side Distance Matrix calls.
     * Uses a dedicated server key if configured; falls back to the main frontend key.
     * NOTE: The server key should be restricted by IP address in Google Cloud Console,
     * NOT by HTTP referrer (PHP requests have no Referer header).
     */
    public function getGoogleServerApiKey(?string $scopeCode = null): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_SERVER_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        if ($encrypted) {
            return $this->encryptor->decrypt($encrypted);
        }

        // Fallback to main key
        return $this->getGoogleApiKey($scopeCode);
    }

    public function getGoogleMapId(?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_MAP_ID,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    public function getStoreLat(?string $scopeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_LAT,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    public function getStoreLng(?string $scopeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_LNG,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    public function getPricePerKm(?string $scopeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_PRICE_PER_KM,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    public function getMaxDistanceKm(?string $scopeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_DISTANCE,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }
}
