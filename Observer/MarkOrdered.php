<?php
/**
 * Burns the offer coupon once an order uses it.
 *
 * ponytail: two checkouts with the same code at the same second can both pass;
 * lock the row in a before-submit observer if that ever matters.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Observer;

use Kkkonrad\PriceOffer\Model\CouponIssuer;
use Kkkonrad\PriceOffer\Model\Status;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class MarkOrdered implements ObserverInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function execute(Observer $observer): void
    {
        $code = (string)$observer->getEvent()->getOrder()->getCouponCode();
        if (stripos($code, CouponIssuer::PREFIX) !== 0) {
            return;
        }
        $this->resource->getConnection()->update(
            $this->resource->getTableName('kkkonrad_price_offer'),
            ['status' => Status::ORDERED],
            ['coupon_code = ?' => $code]
        );
    }
}
