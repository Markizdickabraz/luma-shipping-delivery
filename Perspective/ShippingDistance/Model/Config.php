<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED               = 'shipping_distance/general/enabled';
    private const XML_PATH_GOOGLE_API_KEY        = 'shipping_distance/general/google_server_api_key';
    private const XML_PATH_GOOGLE_MAP_ID         = 'shipping_distance/general/google_map_id';
    private const XML_PATH_STORE_LAT            = 'shipping_distance/general/store_lat';
    private const XML_PATH_STORE_LNG            = 'shipping_distance/general/store_lng';
    private const XML_PATH_PRICE_PER_KM         = 'shipping_distance/general/price_per_km';
    private const XML_PATH_MAX_DISTANCE         = 'shipping_distance/general/max_distance_km';
    private const XML_PATH_SEARCH_COUNTRY_CODES = 'shipping_distance/general/search_country_codes';
    private const XML_PATH_CONTACT_EMAIL        = 'shipping_distance/general/contact_email';

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
     * Returns the single Google Maps API key (decrypted).
     *
     * ONE-KEY STRATEGY
     * ─────────────────────────────────────────────────────────────────────
     * This same key is used in two places:
     *
     *   1. Frontend (Maps JS SDK / Geocoder) — exposed in jsLayout.
     *      Secure it in Google Cloud Console by adding an **HTTP Referrer**
     *      restriction to your domain (e.g. https://yourdomain.com/*).
     *
     *   2. Backend (Distance Matrix API, PHP) — DistanceCalculator sets
     *      a `Referer` header equal to the store base URL so the key passes
     *      the same referrer restriction even from server-side PHP requests.
     *
     * This means you only ever configure and rotate one key.
     * ─────────────────────────────────────────────────────────────────────
     * NOTE: Do NOT restrict this key by IP address; the frontend would break.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function getGoogleApiKey(?string $scopeCode = null): string
    {
        $encrypted = (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
        return $this->encryptor->decrypt($encrypted);
    }

    /**
     * Alias kept for backwards-compatibility with DistanceCalculator.
     */
    public function getGoogleServerApiKey(?string $scopeCode = null): string
    {
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

    /**
     * ISO 3166-1 alpha-2 country codes (comma-separated) to bias Nominatim results.
     * Example: "ua,pl"
     */
    public function getSearchCountryCodes(?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SEARCH_COUNTRY_CODES,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }

    /**
     * Contact e-mail used in the Nominatim User-Agent header (required by OSM policy).
     */
    public function getContactEmail(?string $scopeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CONTACT_EMAIL,
            ScopeInterface::SCOPE_STORE,
            $scopeCode
        );
    }
}
