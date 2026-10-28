<?php
/**
 * The "Buy" link in the acceptance email: puts the product in the cart with
 * the offer coupon applied, then shows the cart.
 *
 * GET, because it comes from an email. The coupon code is the secret; the
 * coupon itself only works while the offer is accepted and under 24h old
 * (Plugin\ValidateCouponPlugin), so an old or forwarded link does nothing.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Controller\Offer;

use Kkkonrad\PriceOffer\Model\OfferProduct;
use Kkkonrad\PriceOffer\Model\Status;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\PageCache\Version;
use Magento\Framework\Math\Random;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

class Buy implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly ResourceConnection $resource,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly Cart $cart,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly Random $random,
        private readonly OfferProduct $offerProduct
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create()->setPath('checkout/cart');
        $code = (string)$this->request->getParam('code');

        $connection = $this->resource->getConnection();
        $offer = $code === '' ? false : $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('kkkonrad_price_offer'))
                ->where('coupon_code = ?', $code)
                ->where('status = ?', Status::ACCEPTED)
                ->where('coupon_expires_at > ?', gmdate('Y-m-d H:i:s'))
                ->where('store_id = ?', (int)$this->storeManager->getStore()->getId())
        );
        if (!$offer) {
            $this->messageManager->addErrorMessage(__('This offer has expired or was already used.'));
            return $redirect;
        }

        $product = $this->productRepository->getById((int)$offer['product_id'], false, (int)$offer['store_id']);
        $request = ['qty' => 1];
        if ($offer['variant_id']) {
            // The exact variant the price was agreed for, so no options to pick
            $variant = $this->offerProduct->getVariant($product, (int)$offer['variant_id']);
            $request['super_attribute'] = $variant['super'] ?? [];
        }
        try {
            $this->cart->addProduct($product, $request);
        } catch (LocalizedException) {
            // Variant sold out since, or a bundle / product with required custom options
            $this->messageManager->addNoticeMessage(
                __('Choose the options, add the product to the cart and enter code %1 there.', $code)
            );
            return $redirect->setUrl($product->getProductUrl());
        }
        $this->cart->getQuote()->setCouponCode($code);
        $this->cart->save();
        $this->invalidatePrivateContent();

        if ($this->cart->getQuote()->getCouponCode() === $code) {
            $this->messageManager->addSuccessMessage(__('Your negotiated price has been applied.'));
        } else {
            $this->messageManager->addErrorMessage(__('The product is in your cart, but code %1 could not be applied.', $code));
        }

        return $redirect;
    }

    /**
     * Core bumps this cookie on POST only. Without it a GET that fills the cart
     * leaves the browser's cached customer data alone: Hyvä then shows the old
     * minicart count and never fetches the flash message.
     */
    private function invalidatePrivateContent(): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setDuration(Version::COOKIE_PERIOD)
            ->setPath('/')
            ->setSecure($this->request->isSecure())
            ->setHttpOnly(false)
            ->setSameSite('Lax');
        $this->cookieManager->setPublicCookie(Version::COOKIE_NAME, $this->random->getUniqueHash(), $metadata);
    }
}
