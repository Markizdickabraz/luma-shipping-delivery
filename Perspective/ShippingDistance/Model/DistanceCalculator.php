<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class DistanceCalculator
{
    private const GOOGLE_DISTANCE_MATRIX_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public function __construct(
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Calculate driving distance from store to customer using Google Distance Matrix API.
     *
     * @throws \RuntimeException
     */
    public function calculate(float $customerLat, float $customerLng): array
    {
        $storeLat = $this->config->getStoreLat();
        $storeLng = $this->config->getStoreLng();
        // Use dedicated server key (IP-restricted) for backend calls.
        // Falls back to main key if server key is not configured.
        $apiKey   = $this->config->getGoogleServerApiKey();

        $params = http_build_query([
            'origins'      => "{$storeLat},{$storeLng}",
            'destinations' => "{$customerLat},{$customerLng}",
            'mode'         => 'driving',
            'units'        => 'metric',
            'key'          => $apiKey,
        ]);

        $url = self::GOOGLE_DISTANCE_MATRIX_URL . '?' . $params;

        $this->curl->get($url);

        $response = $this->curl->getBody();
        $status   = $this->curl->getStatus();

        if ($status !== 200) {
            $this->logger->error('Google Distance Matrix API HTTP error', [
                'status'   => $status,
                'response' => $response,
            ]);
            throw new \RuntimeException('Distance calculation service unavailable.');
        }

        $data = $this->json->unserialize($response);

        $apiStatus = $data['status'] ?? '';
        if ($apiStatus !== 'OK') {
            $this->logger->error('Google Distance Matrix API error status', [
                'api_status'   => $apiStatus,
                'error_message'=> $data['error_message'] ?? '(none)',
                'hint'         => match ($apiStatus) {
                    'REQUEST_DENIED'  => 'API key is invalid, missing, or Distance Matrix API is not enabled in Google Cloud Console.',
                    'OVER_DAILY_LIMIT' => 'Billing is not set up or the API quota has been exceeded.',
                    'OVER_QUERY_LIMIT' => 'Too many requests. Check your quota.',
                    default           => 'See https://developers.google.com/maps/documentation/distance-matrix/overview',
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
