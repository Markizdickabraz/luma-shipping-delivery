<?php

declare(strict_types=1);

namespace Perspective\ShippingDistance\Plugin;

use Magento\OfflineShipping\Model\Carrier\Flatrate;
use Magento\Quote\Model\Quote\Address\RateRequest;

/**
 * Hide the Flat Rate shipping method when distance-based shipping is available.
 *
 * Plugs into Flatrate::collectRates() — clean alternative to the previous
 * ReflectionProperty approach that mutated Magento\Shipping\Model\Rate\Result.
 */
class HideFlatRatePlugin
{
    /**
     * Return false (= no rates) if distance shipping has already been calculated
     * and is available for this quote, effectively hiding Flat Rate.
     *
     * @param Flatrate    $subject
     * @param mixed       $result   Original return value
     * @param RateRequest $request
     * @return mixed
     */
    public function afterCollectRates(
        Flatrate $subject,
        mixed $result,
        RateRequest $request
    ): mixed {
        $items = $request->getAllItems();
        $quote = $items[0]?->getQuote() ?? null;

        if ($quote === null) {
            return $result;
        }

        // Only hide Flat Rate when distance shipping is explicitly available
        if ((bool) $quote->getData('distance_shipping_available')) {
            return false;
        }

        return $result;
    }
}
