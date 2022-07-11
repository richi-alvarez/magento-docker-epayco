<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Helper;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class WebhooksSetupTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysTestAndLive.php
     */
    public function testCacheInvalidation()
    {
        $webhooksSetup = $this->objectManager->get(\StripeIntegration\Payments\Helper\WebhooksSetup::class);

        $oldWebhookModels = $webhooksSetup->getConfiguredWebhooks();
        $configurations = $webhooksSetup->createMissingWebhooks($oldWebhookModels);

        $this->assertTrue(is_array($configurations));
        $this->assertNotEmpty($configurations);
        $this->assertCount(1, $configurations);
    }
}
