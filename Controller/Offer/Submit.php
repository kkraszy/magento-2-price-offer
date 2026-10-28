<?php
/**
 * Receives the offer form from the product page.
 *
 * A plain POST, so it works the same under Hyvä, Luma and with JavaScript off.
 * The form key is checked by Magento's CSRF validator before we get here.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Controller\Offer;

use Kkkonrad\PriceOffer\Model\OfferProduct;
use Kkkonrad\PriceOffer\Model\Mailer;
use Kkkonrad\PriceOffer\Model\Status;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

class Submit implements HttpPostActionInterface
{
    private const MAX_MESSAGE = 2000;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly Mailer $mailer,
        private readonly OfferProduct $offerProduct
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $store = $this->storeManager->getStore();

        try {
            $product = $this->productRepository->getById((int)$this->request->getParam('product'), false, $store->getId());
        } catch (NoSuchEntityException) {
            return $redirect->setPath('/');
        }
        // Always back to the product, never to a caller-supplied URL
        $redirect->setUrl($product->getProductUrl());

        if (!$product->isVisibleInSiteVisibility() || !in_array($store->getWebsiteId(), $product->getWebsiteIds())) {
            return $redirect->setPath('/');
        }

        $name = trim((string)$this->request->getParam('name'));
        $email = trim((string)$this->request->getParam('email'));
        $price = round((float)str_replace(',', '.', (string)$this->request->getParam('price')), 2);
        $message = mb_substr(trim((string)$this->request->getParam('message')), 0, self::MAX_MESSAGE);

        if ($name === '' || mb_strlen($name) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $price <= 0) {
            $this->messageManager->addErrorMessage(__('Please enter your name, a valid email and a price above zero.'));
            return $redirect;
        }

        $productName = (string)$product->getName();
        $priced = $product;
        $variantId = null;
        if ($this->offerProduct->isConfigurable($product)) {
            $variant = $this->offerProduct->getVariant($product, (int)$this->request->getParam('variant'));
            if (!$variant) {
                $this->messageManager->addErrorMessage(__('Please choose the variant you are making an offer for.'));
                return $redirect;
            }
            $priced = $variant['product'];
            $variantId = (int)$priced->getId();
            $productName .= ' (' . $variant['name'] . ')';
        }

        $offer = [
            'product_id' => (int)$product->getId(),
            'variant_id' => $variantId,
            'store_id' => (int)$store->getId(),
            'product_name' => $productName,
            'product_sku' => (string)$priced->getSku(),
            'product_price' => $this->offerProduct->getShownPrice($priced, $store, (string)$store->getCurrentCurrencyCode()),
            'price' => $price,
            'currency' => (string)$store->getCurrentCurrencyCode(),
            'name' => $name,
            'email' => $email,
            'message' => $message,
            'status' => Status::NEW,
        ];
        $connection = $this->resource->getConnection();
        $connection->insert($this->resource->getTableName('kkkonrad_price_offer'), $offer);
        $offer['offer_id'] = (int)$connection->lastInsertId();

        $this->mailer->notifyShop($offer);
        $this->messageManager->addSuccessMessage(__('Thank you. We have received your offer and will reply by email.'));

        return $redirect;
    }
}
