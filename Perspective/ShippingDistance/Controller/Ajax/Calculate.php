<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Controller\Ajax;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Perspective\ShippingDistance\Model\Config;
use Perspective\ShippingDistance\Model\DistanceCalculator;

class Calculate implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface    $request,
        private readonly JsonFactory         $jsonFactory,
        private readonly Config              $config,
        private readonly DistanceCalculator  $distanceCalculator,
        private readonly CheckoutSession     $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly FormKeyValidator    $formKeyValidator
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setData([
                'success' => false,
                'message' => __('Invalid form key. Please refresh the page.')
            ]);
        }

        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => __('Distance shipping is disabled.')
            ]);
        }

        $lat = (float) $this->request->getParam('lat');
        $lng = (float) $this->request->getParam('lng');

        // Validate coordinate ranges (also rejects 0.0/0.0 as an invalid location)
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return $result->setData([
                'success' => false,
                'message' => __('Invalid coordinates.')
            ]);
        }

        try {
            $data = $this->distanceCalculator->calculate($lat, $lng);

            // Save to quote
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $quote->setData('distance_km', $data['distance_km']);
                $quote->setData('distance_shipping_price', $data['price']);
                $quote->setData('distance_shipping_available', $data['available'] ? 1 : 0);

                // Force recollect totals to apply the new shipping rates
                $quote->getShippingAddress()->setCollectShippingRates(true);
                $this->quoteRepository->save($quote);
            }

            return $result->setData([
                'success'      => true,
                'distance_km'  => $data['distance_km'],
                'price'        => $data['price'],
                'available'    => $data['available'],
                'max_distance' => $data['max_distance'],
                'message'      => !$data['available']
                    ? __('Delivery is not available for distances over %1 km.', $data['max_distance'])
                    : ''
            ]);
        } catch (\RuntimeException $e) {
            return $result->setData([
                'success' => false,
                'message' => __($e->getMessage())
            ]);
        }
    }
}
