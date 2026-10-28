<?php
/**
 * Turns an accepted offer into a one-off coupon: a cart price rule taking a
 * percentage off one unit of that SKU.
 *
 * Percent, not a fixed amount. The customer offers against the price they saw,
 * which here includes VAT, while a fixed discount comes off the net price with
 * VAT added after - an offer of 50,00 on a 60,27 product came out at 47,64. A
 * percentage cuts net and gross alike, so the total lands on the offer
 * whatever the tax and currency settings.
 *
 * The rule's own dates only resolve to a day, so the real 24h limit lives in
 * `coupon_expires_at` and is enforced by Plugin\ValidateCouponPlugin.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\Rule\Condition\Combine as ActionsCombine;
use Magento\SalesRule\Model\Rule\Condition\Product as ProductCondition;
use Magento\Store\Model\StoreManagerInterface;

class CouponIssuer
{
    public const PREFIX = 'OFFER-';
    public const LIFETIME = 86400;

    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupCollectionFactory $groupCollectionFactory,
        private readonly TimezoneInterface $timezone,
        private readonly Random $random,
        private readonly OfferProduct $offerProduct
    ) {
    }

    /**
     * @return array coupon_code, rule_id, coupon_expires_at - or [] when the
     *               offer is not below today's price and needs no discount
     */
    public function issue(array $offer): array
    {
        $store = $this->storeManager->getStore((int)$offer['store_id']);
        // The variant for a configurable: its own price, and its SKU so only it is discounted
        $productId = (int)($offer['variant_id'] ?? 0) ?: (int)$offer['product_id'];
        $product = $this->productRepository->getById($productId, false, $store->getId(), true);

        $percent = self::percentFor(
            (float)$offer['price'],
            $this->offerProduct->getShownPrice($product, $store, $offer['currency']),
            $this->offerProduct->getNetPrice($product, $store, $offer['currency'])
        );
        if ($percent <= 0) {
            return [];
        }

        $code = self::PREFIX . $this->random->getRandomString(10, Random::CHARS_UPPERS . Random::CHARS_DIGITS);
        $expires = time() + self::LIFETIME;

        /** @var Rule $rule */
        $rule = $this->ruleFactory->create();
        $rule->setName(sprintf('Price offer #%d', $offer['offer_id']))
            ->setDescription(sprintf('%s, %s (%s)', $offer['product_sku'], $offer['name'], $offer['email']))
            ->setIsActive(1)
            ->setWebsiteIds([$store->getWebsiteId()])
            ->setCustomerGroupIds($this->groupCollectionFactory->create()->getAllIds())
            ->setCouponType(Rule::COUPON_TYPE_SPECIFIC)
            ->setCouponCode($code)
            ->setUsesPerCoupon(1)
            ->setUsesPerCustomer(1)
            ->setFromDate($this->timezone->date(null, null, true)->format('Y-m-d'))
            ->setToDate($this->timezone->date($expires, null, true)->format('Y-m-d'))
            ->setSimpleAction(Rule::BY_PERCENT_ACTION)
            ->setDiscountAmount($percent)
            ->setDiscountQty(1)
            ->setApplyToShipping(0)
            ->setStopRulesProcessing(1)
            ->setSortOrder(0)
            // Without a label the cart prints the description - the customer's own email
            ->setStoreLabels([0 => $code]);
        $rule->loadPost(['actions' => [
            '1' => ['type' => ActionsCombine::class, 'aggregator' => 'all', 'value' => '1', 'new_child' => ''],
            '1--1' => ['type' => ProductCondition::class, 'attribute' => 'sku', 'operator' => '==', 'value' => $product->getSku()],
        ]]);
        $rule->save();

        return [
            'coupon_code' => $code,
            'rule_id' => (int)$rule->getId(),
            'coupon_expires_at' => gmdate('Y-m-d H:i:s', $expires),
        ];
    }

    /**
     * Discount percentage that brings one unit as close to the offer as the
     * cart can get without going above it.
     *
     * Exactly the offer is often impossible: the cart works in whole grosze of
     * the net price and adds VAT after, so at 23% on 59,00 net the totals next
     * to an offer of 65,00 are 64,99 (52,84 net) and 65,01 (52,85 net). We take
     * the largest net whose gross does not exceed the offer. The resulting
     * discount is a whole number of grosze, so rounding the percentage up (the
     * column keeps 4 decimals) cannot tip it into the next grosz below ~5 000.
     *
     * @param float $offer offered price, gross as shown
     * @param float $gross price as shown on the product page
     * @param float $net   the same price before tax
     */
    public static function percentFor(float $offer, float $gross, float $net): float
    {
        if ($net <= 0 || $offer >= $gross) {
            return 0.0;
        }
        $ratio = $gross / $net;
        $grossOf = static fn (float $n): float => round($n + round($n * ($ratio - 1), 2), 2);

        $targetNet = floor(round($offer / $ratio * 100, 6)) / 100;
        if ($grossOf($targetNet + 0.01) <= $offer) {
            $targetNet += 0.01;
        }

        return ceil(round((1 - $targetNet / $net) * 1000000, 6)) / 10000;
    }
}
