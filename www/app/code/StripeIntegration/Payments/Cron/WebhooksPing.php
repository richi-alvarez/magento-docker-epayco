<?php

namespace StripeIntegration\Payments\Cron;

class WebhooksPing
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhooksCollection,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentElement\Collection $paymentElementCollection,
        \StripeIntegration\Payments\Model\ResourceModel\PaymentIntent\Collection $paymentIntentCollection,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\ResourceModel\Order\Collection $orderCollection
    ) {
        $this->config = $config;
        $this->webhooksCollection = $webhooksCollection;
        $this->paymentElementCollection = $paymentElementCollection;
        $this->paymentIntentCollection = $paymentIntentCollection;
        $this->webhooksSetup = $webhooksSetup;
        $this->cache = $cache;
        $this->orderCollection = $orderCollection;
    }

    public function execute()
    {
        $this->pingWebhookEndpoints();
        $this->cancelAbandonedPayments();
        $this->clearStaleData();
    }

    public function pingWebhookEndpoints()
    {
        $configurations = $this->webhooksSetup->getStoreViewAPIKeys();
        $processed = [];

        foreach ($configurations as $configuration)
        {
            $secretKey = $configuration['api_keys']['sk'];
            if (empty($secretKey))
                continue;

            if (in_array($secretKey, $processed))
                continue;

            $processed[$secretKey] = $secretKey;

            $this->config->reInitStripeFromStoreCode($configuration['code'], $configuration['mode']);
            $stripe = $this->config->getStripeClient();

            $localTime = time();
            $product = $stripe->products->create([
               'name' => 'Webhook Ping',
               'type' => 'service',
               'metadata' => [
                    "pk" => $configuration['api_keys']['pk']
               ]
            ]);
            $timeDifference = $product->created - ($localTime + 1); // The 1 added second accounts for the delay in creating the product
            $this->cache->save($timeDifference, $key = "stripe_api_time_difference", $tags = ["stripe_payments"], $lifetime = 24 * 60 * 60);

            $product->delete();
        }
    }

    public function cancelAbandonedPayments($minAgeMinutes = 2 * 60, $maxAgeMinutes = 6 * 60)
    {
        $timeDifference = $this->cache->load("stripe_api_time_difference");
        if (!is_numeric($timeDifference))
            $timeDifference = 0;

        $now = time() + $timeDifference;
        $fromTime = $now - ($maxAgeMinutes * 60);
        $toTime = $now - ($minAgeMinutes * 60);

        $configurations = $this->webhooksSetup->getStoreViewAPIKeys();
        $canceled = $processed = [];

        foreach ($configurations as $configuration)
        {
            $secretKey = $configuration['api_keys']['sk'];
            if (empty($secretKey))
                continue;

            if (in_array($secretKey, $processed))
                continue;

            $processed[$secretKey] = $secretKey;

            $stripe = new \Stripe\StripeClient($secretKey);
            $paymentIntents = $stripe->paymentIntents->all([
                'limit' => 100,
                'created' => [
                    'gte' => $fromTime,
                    'lte' => $toTime
                ]
            ]);

            foreach ($paymentIntents->autoPagingIterator() as $paymentIntent)
            {
                if ($this->isAbandonedPayment($paymentIntent))
                {
                    $canceled[] = $stripe->paymentIntents->cancel($paymentIntent->id, ['cancellation_reason' => 'abandoned']);
                }
            }

            $setupIntents = $stripe->setupIntents->all([
                'limit' => 100,
                'created' => [
                    'gte' => $fromTime,
                    'lte' => $toTime
                ]
            ]);

            foreach ($setupIntents->autoPagingIterator() as $setupIntent)
            {
                if (in_array($setupIntent->status, ['processing', 'canceled', 'succeeded', 'requires_action']))
                    continue;

                $canceled[] = $stripe->setupIntents->cancel($setupIntent->id, ['cancellation_reason' => 'abandoned']);
            }
        }

        return $canceled;
    }

    protected function isAbandonedPayment($paymentIntent)
    {
        if (empty($paymentIntent->metadata->{"Order #"}))
            return false;

        // requires_action is used by Vouchers, i.e. the customer needs to go pay the voucher
        if (in_array($paymentIntent->status, ['processing', 'requires_capture', 'canceled', 'succeeded', 'requires_action']))
            return false;

        return true;
    }

    public function clearStaleData()
    {
        $this->paymentElementCollection->deleteOlderThan(12);
        $this->paymentIntentCollection->deleteOlderThan(12);
    }
}
