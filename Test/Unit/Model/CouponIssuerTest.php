<?php
declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Test\Unit\Model;

use Kkkonrad\PriceOffer\Model\CouponIssuer;
use PHPUnit\Framework\TestCase;

/**
 * Replays the cart's arithmetic (net in grosze, VAT on top) for each case and
 * checks the customer pays the offer, or the closest amount below it.
 */
class CouponIssuerTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testLandsOnOrJustBelowOffer(float $offer, float $gross, float $net, float $expected): void
    {
        $percent = CouponIssuer::percentFor($offer, $gross, $net);
        $netAfter = $net - round($net * $percent / 100, 2);
        $paid = round($netAfter + round($netAfter * ($gross / $net - 1), 2), 2);

        $this->assertSame($expected, $paid);
    }

    public static function cases(): array
    {
        return [
            'VAT 23%, exact total exists' => [50.00, 60.27, 49.00, 50.00],
            'VAT 23%, exact total impossible' => [65.00, 72.57, 59.00, 64.99],
            'no tax' => [25.00, 34.00, 34.00, 25.00],
            'no tax, odd grosze' => [19.99, 23.17, 23.17, 19.99],
            'VAT 8%, large amount' => [3999.00, 4859.99, 4499.99, 3999.00],
        ];
    }

    public function testNoDiscountWhenOfferNotBelowPrice(): void
    {
        $this->assertSame(0.0, CouponIssuer::percentFor(60.27, 60.27, 49.00));
    }
}
