<?php
declare(strict_types=1);

namespace Perspective\ShippingDistance\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Perspective\ShippingDistance\Model\Config;
use Psr\Log\LoggerInterface;

class DistanceCarrier extends AbstractCarrier implements CarrierInterface
{
    protected $_code = 'distance_shipping';
    protected $_isFixed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly Config $moduleConfig,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->moduleConfig->isEnabled()) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();

        // Ціна зберігається в quote як custom attribute після AJAX виклику
        $shippingPrice = (float) $request->getPackageValue();  // буде перезаписано нижче
        $quote = $request->getAllItems()[0]?->getQuote() ?? null;

        if ($quote === null) {
            return false;
        }

        $distanceKm   = (float) $quote->getData('distance_km');
        $shippingPrice = (float) $quote->getData('distance_shipping_price');
        $available     = (bool) $quote->getData('distance_shipping_available');

        // Якщо координати ще не розраховані — не показуємо метод
        if (!$distanceKm) {
            return false;
        }

        // Якщо відстань перевищує ліміт — повертаємо помилку
        if (!$available) {
            /** @var \Magento\Quote\Model\Quote\Address\RateResult\Error $error */
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage(
                __('Delivery is not available for distances over %1 km.', $this->moduleConfig->getMaxDistanceKm())
            );
            $result->append($error);

            return $result;
        }

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod('distance');
        $method->setMethodTitle(
            __('Distance Delivery (%1 km)', round($distanceKm, 1))
        );
        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);

        $result->append($method);

        return $result;
    }

    public function getAllowedMethods(): array
    {
        return ['distance' => $this->getConfigData('title')];
    }
}
