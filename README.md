# Kkkonrad_PriceOffer

"Negotiate the price" on the product page. The customer names a price, the
shop gets an email, and accepting or rejecting it in the admin emails the
customer back.

Works under Hyvä and Luma/blank with one template: a `<details>` and a plain
POST form, no JavaScript, its own CSS in `em`. No theme classes, because
Tailwind does not generate classes it only sees in a module and Luma has no
Alpine; `em` rather than `rem`, because Luma's root font-size is 10px and
Hyvä's 16px.

## Install

```bash
mkdir -p app/code/Kkkonrad
git clone https://github.com/kkraszy/magento-2-price-offer app/code/Kkkonrad/PriceOffer
bin/magento module:enable Kkkonrad_PriceOffer
bin/magento setup:upgrade
bin/magento cache:flush
```

Requires Magento 2.4 with PHP 8.1+. Cron must run for the clean-up job.

## Flow

1. PDP form → `priceoffer/offer/submit` (form key checked by core CSRF
   validation). A configurable product shows a list of its saleable variants
   with each one's price (`Model/OfferProduct.php`); the offer is for that
   variant — its id, SKU and price are stored. Row in `kkkonrad_price_offer`,
   with product name/SKU/price and currency copied in.
2. Email `kkkonrad_priceoffer_new` to the store's General Contact, Reply-To the
   customer — a counter-offer is just "Reply".
3. Admin: **Sales › Operations › Price offers**. Mass action Accept / Reject
   sets the status and sends `kkkonrad_priceoffer_answer` to the customer.
   Offers already in that status are skipped, so nobody gets the email twice;
   *ordered* offers are skipped by both actions.
4. **Accept issues a coupon** (`Model/CouponIssuer.php`): a cart price rule
   `Price offer #<id>`, a percentage off 1 unit of that SKU (the variant's,
   for a configurable), one use, no stacking (stop further rules), code `OFFER-XXXXXXXXXX`.
   The customer pays the offer, or the nearest grosz below it when the exact
   amount cannot come out of net price + VAT (`CouponIssuer::percentFor`,
   tested in `Test/Unit`). An offer at or above today's price needs no
   discount: it is accepted and the email goes out without a code.
   The email carries the code and a **Buy** button →
   `priceoffer/offer/buy?code=…`, which adds the product — the chosen variant —
   to the cart with the code applied. A variant sold out since, or a bundle,
   goes to the product page with the code in a notice instead.
5. The code works for **24 hours** and only while the offer is *accepted*
   (`Plugin/ValidateCouponPlugin.php`). Rejecting switches it off; placing an
   order with it sets the offer to *ordered* (`Observer/MarkOrdered.php`) and
   burns it.
6. Cron `kkkonrad_priceoffer_delete_expired_rules` (hourly, group `default`)
   deletes the rules of expired codes. The offer keeps its code as history.
   Rules are deleted on expiry only, not right after an order, as core may
   still have a coupon-usage message queued for them.

Why the plugin rather than core limits: `salesrule_coupon.expiration_date` is
never checked, the rule's `to_date` is a whole day, and core's "uses per
coupon" counter is updated by a queue consumer that only runs under cron.

| Where | What |
|---|---|
| `view/frontend/layout/catalog_product_view.xml` | Luma placement (`product.info.extrahint`) |
| `view/frontend/layout/hyva_catalog_product_view.xml` | Hyvä placement (`product.info.additional`) |
| `view/frontend/templates/form.phtml` | the form |
| `Model/Mailer.php` | both emails; a failed send is logged, the offer is kept |
| `Model/CouponIssuer.php` | the rule + coupon, `LIFETIME = 86400` |
| `Controller/Offer/Buy.php` | the email's Buy link |
| `Cron/DeleteExpiredRules.php` | hourly clean-up of expired rules |
| `view/adminhtml/ui_component/kkkonrad_priceoffer_listing.xml` | the grid, read straight off the table |

ACL: `Kkkonrad_PriceOffer::offers`. Templates are overridable in
Marketing › Email Templates.

## Not included

- Two checkouts racing with one code in the same second can both pass.
- A cancelled order does not give the coupon back.
- No rate limiting / captcha beyond the form key.
