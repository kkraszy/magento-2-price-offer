<?php
/**
 * Deletes the cart price rules of offer coupons past their 24h.
 *
 * Only by expiry, not as soon as an order uses the code: core may still have a
 * coupon-usage message queued for it. The offer row keeps its coupon code as
 * history; `rule_id` is cleared by the foreign key.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Psr\Log\LoggerInterface;

class DeleteExpiredRules
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RuleRepositoryInterface $ruleRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): int
    {
        $connection = $this->resource->getConnection();
        $ruleIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('kkkonrad_price_offer'), ['rule_id'])
                ->where('rule_id IS NOT NULL')
                ->where('coupon_expires_at <= ?', gmdate('Y-m-d H:i:s'))
        );

        $deleted = 0;
        foreach ($ruleIds as $ruleId) {
            try {
                $this->ruleRepository->deleteById((int)$ruleId);
                $deleted++;
            } catch (\Throwable $e) {
                $this->logger->error('Price offer: could not delete rule ' . $ruleId . ': ' . $e->getMessage());
            }
        }
        return $deleted;
    }
}
