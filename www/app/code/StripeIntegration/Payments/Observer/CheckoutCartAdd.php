<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

class CheckoutCartAdd implements ObserverInterface
{
    protected $messageManager;
    protected $request;
    protected $redirect;
    protected $helper;
    protected $subscriptions;

    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\App\Response\RedirectInterface $redirect,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory $configurableProductFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->messageManager = $messageManager;
        $this->redirect = $redirect;
        $this->request = $request;
        $this->configurableProductFactory = $configurableProductFactory;
        $this->helper = $helper;
        $this->subscriptions = $subscriptions;
        $this->config = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        $products = [ $this->getProductFromRequest() ];
        $quote = $this->helper->getQuote();
        foreach ($quote->getAllItems() as $quoteItem)
            $products[] = $this->helper->loadProductById($quoteItem->getProductId());

        if (!$this->subscriptions->renewTogetherByProducts($products))
        {
            $observer->getRequest()->setParam('product', false);
            $observer->getRequest()->setParam('super_attribute', false);
            $this->helper->addError(__("Subscriptions that do not renew together must be bought separately."));
        }
    }

    public function getProductFromRequest()
    {
        $postValues = $this->request->getPostValue();
        $productId = $postValues['product'];
        $addProduct = $this->helper->loadProductById($productId);
        if ($addProduct->getTypeId() == 'configurable')
        {
            if (empty($postValues['super_attribute']))
                return $addProduct;

            $attributes = $postValues['super_attribute'];
            $configurableProduct = $this->configurableProductFactory->create();
            $product = $configurableProduct->getProductByAttributes($attributes, $addProduct);
            if ($product)
                $addProduct = $this->helper->loadProductById($product->getId());
        }

        return $addProduct;
    }
}
