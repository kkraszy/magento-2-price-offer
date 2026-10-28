<?php
/**
 * Makes an offer coupon valid only while its offer is accepted and under 24h old.
 *
 * Neither part can be left to core: `salesrule_coupon.expiration_date` is never
 * checked, and the "1 use" counter is updated by a queue consumer that only
 * runs with cron. Our own row is the source of truth - rejecting the offer or
 * placing the order (Observer\MarkOrdered) switches the code off at once.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Plugin;

use Kkkonrad\PriceOffer\Model\CouponIssuer;
use Kkkonrad\PriceOffer\Model\Status;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\ValidateCoupon;

class ValidateCouponPlugin
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function afterExecute(ValidateCoupon $subject, bool $result, Rule $rule, Address $address, ?string $couponCode = null): bool
    {
        if (!$result || !$couponCode || stripos($couponCode, CouponIssuer::PREFIX) !== 0) {
            return $result;
        }

        $connection = $this->resource->getConnection();
        $valid = (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('kkkonrad_price_offer'), ['offer_id'])
                ->where('coupon_code = ?', $couponCode)
                ->where('status = ?', Status::ACCEPTED)
                ->where('coupon_expires_at > ?', gmdate('Y-m-d H:i:s'))
        );
        if (!$valid) {
            $rule->setIsValidForAddress($address, false);
        }

        return $valid;
    }
}
