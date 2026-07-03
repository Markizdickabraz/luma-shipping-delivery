<?php

declare(strict_types=1);

namespace Perspective\ShippingDistance\Controller\Ajax;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;
use Perspective\ShippingDistance\Model\Config;

class Search implements HttpGetActionInterface
{
    private const MIN_QUERY_LENGTH = 3;
    private const MAX_RESULTS = 5;
    private const CACHE_TTL = 3600; // 1 hour — avoid hammering Nominatim with identical queries
    private const CACHE_TAG = 'shipping_distance_search';
    private const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const REQUEST_TIMEOUT = 5;

    public function __construct(
        private readonly RequestInterface  $request,
        private readonly JsonFactory       $jsonFactory,
        private readonly Config            $config,
        private readonly CurlFactory       $curlFactory,
        private readonly CacheInterface    $cache,
        private readonly JsonSerializer    $serializer,
        private readonly ResolverInterface $localeResolver,
        private readonly LoggerInterface   $logger
    )
    {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => __('Distance shipping is disabled.')
            ]);
        }

        $query = trim((string)$this->request->getParam('q'));

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return $result->setData([
                'success' => false,
                'message' => __('Query too short.')
            ]);
        }

        $cacheKey = $this->getCacheKey($query);
        $cached = $this->cache->load($cacheKey);

        if ($cached !== false) {
            return $result->setData([
                'success' => true,
                'results' => $this->serializer->unserialize($cached)
            ]);
        }

        try {
            $results = $this->fetchSuggestions($query);

            $this->cache->save(
                $this->serializer->serialize($results),
                $cacheKey,
                [self::CACHE_TAG],
                self::CACHE_TTL
            );

            return $result->setData([
                'success' => true,
                'results' => $results
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[ShippingDistance] Address search failed: ' . $e->getMessage());

            return $result->setData([
                'success' => false,
                'message' => __('Could not search for address. Please try again.')
            ]);
        }
    }

    /**
     * Call Nominatim server-side (keeps rate limiting / caching / headers on our end
     * instead of the client hitting Nominatim directly on every keystroke).
     *
     * @param string $query
     * @return array<int, array{label: string, lat: float, lng: float}>
     */
    private function fetchSuggestions(string $query): array
    {
        /** @var Curl $client */
        $client = $this->curlFactory->create();
        $client->setTimeout(self::REQUEST_TIMEOUT);
        $client->setOption(CURLOPT_CONNECTTIMEOUT, self::REQUEST_TIMEOUT);

        // Nominatim usage policy requires a descriptive, identifying User-Agent
        $client->addHeader('User-Agent', $this->buildUserAgent());
        $client->addHeader('Accept-Language', $this->getLocaleLanguage());

        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => self::MAX_RESULTS
        ];

        // Bias results toward configured region if available
        $countryCodes = $this->config->getSearchCountryCodes();
        if ($countryCodes) {
            $params['countrycodes'] = $countryCodes;
        }

        $client->get(self::NOMINATIM_ENDPOINT . '?' . http_build_query($params));

        $status = $client->getStatus();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Nominatim returned HTTP %d', $status));
        }

        $body = $client->getBody();
        try {
            $decoded = $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException) {
            throw new \RuntimeException('Invalid response from Nominatim.');
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid response from Nominatim.');
        }

        $results = [];
        foreach ($decoded as $item) {
            if (!isset($item['lat'], $item['lon'], $item['display_name'])) {
                continue;
            }

            $results[] = [
                'label' => $item['display_name'],
                'lat' => (float)$item['lat'],
                'lng' => (float)$item['lon']
            ];
        }

        return $results;
    }

    private function buildUserAgent(): string
    {
        return sprintf(
            'Perspective_ShippingDistance/1.0 (%s)',
            $this->config->getContactEmail() ?: 'noreply@example.com'
        );
    }

    private function getLocaleLanguage(): string
    {
        $locale = $this->localeResolver->getLocale();
        return str_replace('_', '-', $locale);
    }

    private function getCacheKey(string $query): string
    {
        return 'shipping_distance_search_' . md5(mb_strtolower($query) . '_' . $this->getLocaleLanguage());
    }
}
