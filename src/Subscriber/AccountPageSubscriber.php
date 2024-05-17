<?php declare(strict_types=1);

namespace Nds\ProductReorder\Subscriber;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Shopware\Storefront\Page\Account\Overview\AccountOverviewPageLoadedEvent;
use Shopware\Storefront\Page\Page;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AccountPageSubscriber implements EventSubscriberInterface
{
    public function __construct(protected SalesChannelRepository $productRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class => 'onAccountOrderPageLoaded',
            AccountOverviewPageLoadedEvent::class => 'onAccountOverviewPageLoaded',
        ];
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $orders = $page->getOrders()->getElements();

        if (empty($orders)) {
            return;
        }

        $productIds = $this->getProductIds($orders);

        $this->adjustPage($page, $productIds, $event->getSalesChannelContext());
    }

    public function onAccountOverviewPageLoaded(AccountOverviewPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $order = $page->getNewestOrder();

        if ($order === null) {
            return;
        }

        $productIds = $this->getProductIds([$order]);

        $this->adjustPage($page, $productIds, $event->getSalesChannelContext());
    }

    protected function getProductIds(array $orders): array
    {
        $productIds = [];

        /** @var OrderEntity $order */
        foreach ($orders as $order) {
            $lineItems = $order->getLineItems();

            if ($lineItems === null) {
                continue;
            }

            $productIds[] = array_values($lineItems->fmap(fn ($lineItem) => $lineItem->getProductId() ?? false));
        }

        return array_merge(...$productIds);
    }

    protected function adjustPage(Page $page, array $productIds, SalesChannelContext $context): void
    {
        $criteria = new Criteria($productIds);
        $criteria->addFilter(new EqualsFilter('available', true));

        $availableProducts = $this->productRepository->search($criteria, $context)->filter(fn (SalesChannelProductEntity $product) => $product->getCalculatedMaxPurchase() > 0)->getKeys();

        if (empty($availableProducts)) {
            return;
        }

        $page->addArrayExtension('ndsReorder', ['availableProducts' => $availableProducts]);
    }
}
