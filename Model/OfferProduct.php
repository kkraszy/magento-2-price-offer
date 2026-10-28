<?php
/**
 * What an offer is made against: the price the customer saw, and for a
 * configurable product the one variant they picked - variants can be priced
 * differently, so an offer on "the product" has no single price to compare.
 *
 * Also the form's view model, so the template and the controllers agree on
 * which variants exist.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

class OfferProduct implements ArgumentInterface
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isConfigurable(ProductInterface $product): bool
    {
        return $product->getTypeId() === Configurable::TYPE_CODE;
    }

    /**
     * The price as the product page shows it: final price with the tax display
     * applied, in the given currency. Not getFinalPrice() - that is net for a
     * simple product but gross for a configurable one.
     */
    public function getShownPrice(ProductInterface $product, StoreInterface $store, string $currency): float
    {
        $amount = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        return $amount * ((float)$store->getBaseCurrency()->getRate($currency) ?: 1.0);
    }

    /**
     * Net (before tax) counterpart of getShownPrice(); equal when no tax applies.
     */
    public function getNetPrice(ProductInterface $product, StoreInterface $store, string $currency): float
    {
        $amount = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getBaseAmount();
        return $amount * ((float)$store->getBaseCurrency()->getRate($currency) ?: 1.0);
    }

    /**
     * @return array<int, string> saleable variant id => "Blue, 28 - 60,27 zł"
     */
    public function getVariantOptions(ProductInterface $parent): array
    {
        $store = $this->storeManager->getStore();
        $options = [];
        foreach ($this->getVariants($parent) as $id => $variant) {
            $price = $this->getShownPrice($variant['product'], $store, (string)$store->getCurrentCurrencyCode());
            $options[$id] = $variant['name'] . ' – ' . $this->priceCurrency->format($price, false, 2, $store);
        }
        return $options;
    }

    /**
     * @return array{product: ProductInterface, name: string, super: array<int, int>}|null
     */
    public function getVariant(ProductInterface $parent, int $variantId): ?array
    {
        return $this->getVariants($parent)[$variantId] ?? null;
    }

    /**
     * Saleable children keyed by id, each with its option labels and the
     * `super_attribute` request that puts exactly it in the cart.
     */
    private function getVariants(ProductInterface $parent): array
    {
        /** @var Configurable $type */
        $type = $parent->getTypeInstance();
        $attributes = $type->getConfigurableAttributesAsArray($parent);

        $variants = [];
        foreach ($type->getUsedProducts($parent) as $child) {
            if (!$child->isSaleable()) {
                continue;
            }
            $super = [];
            $labels = [];
            foreach ($attributes as $attribute) {
                $value = (int)$child->getData($attribute['attribute_code']);
                $super[(int)$attribute['attribute_id']] = $value;
                foreach ($attribute['values'] as $option) {
                    if ((int)$option['value_index'] === $value) {
                        $labels[] = $option['store_label'] ?? $option['label'];
                    }
                }
            }
            $variants[(int)$child->getId()] = ['product' => $child, 'name' => implode(', ', $labels), 'super' => $super];
        }
        return $variants;
    }
}
