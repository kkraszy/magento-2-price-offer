<?php
/**
 * Accept or reject the selected offers and tell each customer. Accepting
 * issues a 24h coupon; rejecting switches off any coupon issued before, as
 * the coupon is only honoured while its offer is accepted.
 *
 * Offers already in the target status are skipped, so a double click does
 * not send the customer the same email twice.
 */

declare(strict_types=1);

namespace Kkkonrad\PriceOffer\Controller\Adminhtml\Offer;

use Kkkonrad\PriceOffer\Model\CouponIssuer;
use Kkkonrad\PriceOffer\Model\Mailer;
use Kkkonrad\PriceOffer\Model\Status;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

class MassStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Kkkonrad_PriceOffer::offers';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly ResourceConnection $resource,
        private readonly Mailer $mailer,
        private readonly CouponIssuer $couponIssuer
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        $status = (string)$this->getRequest()->getParam('status');
        if (!in_array($status, [Status::ACCEPTED, Status::REJECTED], true)) {
            return $redirect;
        }

        /*
         * Selection goes through the grid's own data provider, not
         * Filter::getCollection(): that one needs a resource model, and the
         * grid reads the table without one. Without the guard below, an empty
         * selection would apply no filter and hit every offer.
         */
        $request = $this->getRequest();
        if (!$request->getParam(Filter::SELECTED_PARAM) && $request->getParam(Filter::EXCLUDED_PARAM) === null) {
            $this->messageManager->addErrorMessage(__('An item needs to be selected. Select and try again.'));
            return $redirect;
        }
        $this->filter->applySelectionOnTargetProvider();
        $dataProvider = $this->filter->getComponent()->getContext()->getDataProvider();
        $dataProvider->setLimit(0, false);

        $table = $this->resource->getTableName('kkkonrad_price_offer');
        $connection = $this->resource->getConnection();

        $count = 0;
        foreach ($dataProvider->getSearchResult()->getItems() as $item) {
            $offer = $item->getData();
            if ($offer['status'] === $status || $offer['status'] === Status::ORDERED) {
                continue;
            }
            $changes = ['status' => $status];
            if ($status === Status::ACCEPTED) {
                $changes += $this->couponIssuer->issue($offer);
            }
            $connection->update($table, $changes, ['offer_id = ?' => (int)$offer['offer_id']]);
            $offer = $changes + $offer;
            $this->mailer->notifyCustomer($offer);
            $count++;
        }

        $this->messageManager->addSuccessMessage(__('%1 offer(s) updated, customers notified.', $count));
        return $redirect;
    }
}
