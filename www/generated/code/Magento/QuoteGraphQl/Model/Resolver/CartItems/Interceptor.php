<?php
namespace Magento\QuoteGraphQl\Model\Resolver\CartItems;

/**
 * Interceptor class for @see \Magento\QuoteGraphQl\Model\Resolver\CartItems
 */
class Interceptor extends \Magento\QuoteGraphQl\Model\Resolver\CartItems implements \Magento\Framework\Interception\InterceptorInterface
{
    use \Magento\Framework\Interception\Interceptor;

    public function __construct(\Magento\Framework\GraphQl\Query\Uid $uidEncoder, \Magento\QuoteGraphQl\Model\CartItem\GetItemsData $getItemsData)
    {
        $this->___init();
        parent::__construct($uidEncoder, $getItemsData);
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(\Magento\Framework\GraphQl\Config\Element\Field $field, $context, \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $pluginInfo = $this->pluginList->getNext($this->subjectType, 'resolve');
        return $pluginInfo ? $this->___callPlugins('resolve', func_get_args(), $pluginInfo) : parent::resolve($field, $context, $info, $value, $args);
    }
}
