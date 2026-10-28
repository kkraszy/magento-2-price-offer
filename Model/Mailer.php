<?php
/**
 * The two emails of a negotiation: the offer to the shop, the answer to the customer.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Url;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Mailer
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Status $status,
        private readonly LoggerInterface $logger,
        private readonly Url $frontendUrl,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Goes to the store's general contact. Reply-To is the customer, so a
     * counter-offer is just "Reply" in the shop's mail client.
     */
    public function notifyShop(array $offer): void
    {
        $storeId = (int)$offer['store_id'];
        $this->send('kkkonrad_priceoffer_new', $offer, $storeId, [
            (string)$this->scopeConfig->getValue('trans_email/ident_general/email', ScopeInterface::SCOPE_STORE, $storeId),
            (string)$this->scopeConfig->getValue('trans_email/ident_general/name', ScopeInterface::SCOPE_STORE, $storeId),
        ], [$offer['email'], $offer['name']]);
    }

    public function notifyCustomer(array $offer): void
    {
        if (!empty($offer['coupon_code'])) {
            // Frontend URL builder: this runs in the admin, whose own builder makes backend links
            $offer['buy_url'] = $this->frontendUrl->setScope((int)$offer['store_id'])
                ->getUrl('priceoffer/offer/buy', ['_query' => ['code' => $offer['coupon_code']], '_nosid' => true]);
            $offer['expires_formatted'] = $this->timezone->formatDateTime(
                $offer['coupon_expires_at'] . ' UTC',
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT,
                null,
                $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (int)$offer['store_id'])
            );
        }
        $this->send('kkkonrad_priceoffer_answer', $offer, (int)$offer['store_id'], [$offer['email'], $offer['name']]);
    }

    /**
     * A failed email must never lose the offer, which is already saved, so it
     * is logged rather than thrown.
     */
    private function send(string $template, array $offer, int $storeId, array $to, ?array $replyTo = null): void
    {
        $vars = $offer + [
            'price_formatted' => $this->format((float)$offer['price'], $storeId, $offer['currency']),
            'product_price_formatted' => $this->format((float)$offer['product_price'], $storeId, $offer['currency']),
            'status_label' => (string)($this->status->getLabels()[$offer['status']] ?? $offer['status']),
            'is_accepted' => $offer['status'] === Status::ACCEPTED,
        ];

        try {
            $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars($vars)
                ->setFromByScope('general', $storeId)
                ->addTo($to[0], $to[1]);
            if ($replyTo) {
                $this->transportBuilder->setReplyTo($replyTo[0], $replyTo[1]);
            }
            $this->transportBuilder->getTransport()->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Price offer email failed: ' . $e->getMessage(), ['offer_id' => $offer['offer_id'] ?? null]);
        }
    }

    private function format(float $amount, int $storeId, string $currency): string
    {
        return $this->priceCurrency->format($amount, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId, $currency);
    }
}
