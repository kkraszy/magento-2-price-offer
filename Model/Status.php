<?php
/**
 * Offer statuses. Doubles as the option source for the admin grid.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Model;

use Magento\Framework\Data\OptionSourceInterface;

class Status implements OptionSourceInterface
{
    public const NEW = 'new';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const ORDERED = 'ordered';

    public function getLabels(): array
    {
        return [
            self::NEW => __('New'),
            self::ACCEPTED => __('Accepted'),
            self::REJECTED => __('Rejected'),
            self::ORDERED => __('Ordered'),
        ];
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->getLabels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
