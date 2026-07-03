<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class DistanceCalculator
{
    private const GOOGLE_DISTANCE_MATRIX_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public function __construct(
        private readonly CurlFactory          $curlFactory,
        private readonly Json                 $json,
        private readonly Config               $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface      $logger
    ) {}

    /**
     * Calculate driving distance from store to customer using Google Distance Matrix API.
     *
     * ONE-KEY STRATEGY: we set a Referer header equal to the store base URL so that
     * the single API key (restricted by HTTP Referrer in Google Cloud Console) is
     * accepted by Google for server-side PHP requests, just as it is for browser
     * requests that carry the real Referer header automatically.
     *
     * @throws \RuntimeException
     */
    public function calculate(float $customerLat, float $customerLng): array
    {
        $storeLat = $this->config->getStoreLat();
        $storeLng = $this->config->getStoreLng();
        $apiKey   = $this->config->getGoogleApiKey();

        $params = http_build_query([
            'origins'      => "{$storeLat},{$storeLng}",
            'destinations' => "{$customerLat},{$customerLng}",
            'mode'         => 'driving',
            'units'        => 'metric',
            'key'          => $apiKey,
        ]);

        $url = self::GOOGLE_DISTANCE_MATRIX_URL . '?' . $params;

        // Use a fresh Curl instance per call (avoids state bleed between requests)
        $curl = $this->curlFactory->create();

        // ONE-KEY: set Referer = store base URL so the referrer-restricted key
        // is accepted by Google even from a PHP/CLI context.
        $storeBaseUrl = $this->storeManager->getStore()->getBaseUrl();
        $curl->addHeader('Referer', $storeBaseUrl);

        $curl->get($url);

        $response = $curl->getBody();
        $status   = $curl->getStatus();

        if ($status !== 200) {
            // Log the masked URL to avoid leaking the API key in log files
            $maskedUrl = preg_replace('/key=[^&]+/', 'key=***', $url);
            $this->logger->error('Google Distance Matrix API HTTP error', [
                'status' => $status,
                'url'    => $maskedUrl,
            ]);
            throw new \RuntimeException('Distance calculation service unavailable.');
        }

        $data = $this->json->unserialize($response);

        $apiStatus = $data['status'] ?? '';
        if ($apiStatus !== 'OK') {
            $this->logger->error('Google Distance Matrix API error status', [
                'api_status'    => $apiStatus,
                'error_message' => $data['error_message'] ?? '(none)',
                'hint'          => match ($apiStatus) {
                    'REQUEST_DENIED'   => 'API key is invalid, missing, Referrer restriction does not match, or Distance Matrix API is not enabled in Google Cloud Console.',
                    'OVER_DAILY_LIMIT' => 'Billing is not set up or the API quota has been exceeded.',
                    'OVER_QUERY_LIMIT' => 'Too many requests. Check your quota.',
                    default            => 'See https://developers.google.com/maps/documentation/distance-matrix/overview',
                },
            ]);
            throw new \RuntimeException('Distance calculation service returned an error: ' . $apiStatus);
        }

        $elementStatus = $data['rows'][0]['elements'][0]['status'] ?? '';
        if ($elementStatus !== 'OK') {
            $this->logger->warning('Google Distance Matrix element not OK', [
                'element_status' => $elementStatus,
            ]);
            throw new \RuntimeException('Could not calculate distance for the selected location.');
        }

        // Google returns distance in meters
        $distanceMeters = (int) ($data['rows'][0]['elements'][0]['distance']['value'] ?? 0);
        $distanceKm     = round($distanceMeters / 1000, 4);

        if ($distanceKm <= 0) {
            throw new \RuntimeException('Could not calculate distance.');
        }

        $maxDistance = $this->config->getMaxDistanceKm();
        $available   = $distanceKm <= $maxDistance;
        $price       = $available ? round($distanceKm * $this->config->getPricePerKm(), 2) : 0.0;

        return [
            'distance_km'  => $distanceKm,
            'price'        => $price,
            'available'    => $available,
            'max_distance' => $maxDistance,
        ];
    }
}
