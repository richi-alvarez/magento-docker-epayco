<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class Webhooks
{
    protected $output = null;

    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\App\Response\Http $response,
        \StripeIntegration\Payments\Logger\WebhooksLogger $webhooksLogger,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Api $api,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Model\Order\Invoice $invoiceModel,
        \Magento\Framework\UrlInterface $urlInterface,
        \StripeIntegration\Payments\Model\ResourceModel\Webhook\Collection $webhookCollection,
        \StripeIntegration\Payments\Model\ResourceModel\Source\CollectionFactory $sourceCollectionFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Helper\WebhooksSetup $webhooksSetup,
        \StripeIntegration\Payments\Helper\Email $emailHelper
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->webhooksLogger = $webhooksLogger;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->api = $api;
        $this->helper = $helper;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->eventManager = $eventManager;
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->orderSender = $orderSender;
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->invoiceModel = $invoiceModel;
        $this->urlInterface = $urlInterface;
        $this->webhookCollection = $webhookCollection;
        $this->sourceCollectionFactory = $sourceCollectionFactory;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->webhooksSetup = $webhooksSetup;
        $this->emailHelper = $emailHelper;
    }

    public function setOutput(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->output = $output;
    }

    public function dispatchEvent($stdEvent = null)
    {
        try
        {
            if (!$stdEvent)
            {
                if ($this->request->getMethod() == 'GET')
                    throw new WebhookException("Your webhooks endpoint is accessible from your location.", 200);

                // Retrieve the request's body and parse it as JSON
                $body = $this->request->getContent();
                $event = json_decode($body, true);
                $stdEvent = json_decode($body);

                $eventType = $this->getEventType($event);
                $this->log("Received $eventType");

                $this->verifyWebhookSignature();
            }
            else
            {
                $event = json_decode(json_encode($stdEvent), true);

                $eventType = $this->getEventType($event);
                $this->log("Received $eventType");
            }

            if (empty($event['type']))
                throw new WebhookException(__("Unknown event type"));

            if ($event['type'] == "product.created")
            {
                $this->onProductCreated($event, $stdEvent);
                $this->log("200 OK");
                return;
            }

            if ($this->cache->load($event['id']) && empty($this->request->getParam('dev')))
                throw new WebhookException(__("Event with ID %1 has already been processed.", $event['id']), 202);

            $this->response->setStatusCode(500);
            $this->eventManager->dispatch($eventType, array(
                    'arrEvent' => $event,
                    'stdEvent' => $stdEvent,
                    'object' => $event['data']['object'],
                    'paymentMethod' => $this->getPaymentMethodFrom($event)
                ));
            $this->response->setStatusCode(200);

            $this->cache($event);
            $this->log("200 OK");
        }
        catch (WebhookException $e)
        {
            if (!empty($e->statusCode))
                $this->response->setStatusCode($e->statusCode);
            else
                $this->response->setStatusCode(202);

            $statusCode = $this->response->getStatusCode();

            $this->error($e->getMessage(), $statusCode, true);

            if (!empty($statusCode) && !empty($event) && $statusCode < 400)
                $this->cache($event);
        }
        catch (\Exception $e)
        {
            $statusCode = 500;
            $this->response->setStatusCode($statusCode);

            $this->log($e->getMessage());
            $this->log($e->getTraceAsString());
            $this->error($e->getMessage(), $statusCode);
        }
    }

    protected function getEventType(array $event)
    {
        if (empty($event['type']))
            return "payload with no event type";

        $eventType = "stripe_payments_webhook_" . str_replace(".", "_", $event['type']);
        return $eventType;
    }

    protected function getPaymentMethodFrom($event)
    {
        if (isset($event['data']['object']['type']))
            $paymentMethod = $event['data']['object']['type'];
        else if (isset($event['data']['object']['payment_method_types']))
            $paymentMethod = implode("_", $event['data']['object']['payment_method_types']);
        else if (isset($event['data']['object']['payment_method_details']))
            $paymentMethod = $event['data']['object']['payment_method_details']['type'];
        else
            $paymentMethod = '';

        return $paymentMethod;
    }

    public function onProductCreated($event, $stdEvent)
    {
        if ($event['data']['object']['name'] == "Webhook Configuration")
        {
            $storeCode = $event['data']['object']['metadata']['store_code'];
            $mode = ucfirst($event['data']['object']['metadata']['mode']) . " Mode";
            $this->log("Received automatic webhook configuration for $storeCode ($mode)");
            $this->eventManager->dispatch("automatic_webhook_configuration", array('event' => $stdEvent));
        }
        else if ($event['data']['object']['name'] == "Webhook Ping")
        {
            $this->webhookCollection->pong($event['data']['object']['metadata']['pk']);
        }
    }

    public function error($msg, $status, $displayError = false)
    {
        if ($this->output)
        {
            if ($status)
            {
                if ($status < 300)
                    return $this->output->writeln("$status $msg");
                else
                    return $this->output->writeln("<error>$status $msg</error>");
            }
            else
                return $this->output->writeln("<error>$msg</error>");
        }

        if ($status && $status > 0)
            $this->log("$status $msg");
        else
            $this->log("No status: $msg");

        if (!$displayError)
            $msg = "An error has occurred. Please check var/log/stripe_payments_webhooks.log for more details.";

        $this->response
            ->setHeader('Content-Type', 'text/plain', $overwriteExisting = true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContent($msg);
    }

    public function log($msg)
    {
        if ($this->output)
            $this->output->writeln($msg);
        // Magento 2.0.0 - 2.4.3
        else if (method_exists($this->webhooksLogger, 'addInfo'))
            $this->webhooksLogger->addInfo($msg);
        // Magento 2.4.4+
        else
            $this->webhooksLogger->info($msg);
    }

    public function verifyWebhookSignature()
    {
        $signingSecrets = $this->config->getWebhooksSigningSecrets();
        if (empty($signingSecrets))
            return;

        $success = false;
        $errors = [];
        $count = 1;
        foreach ($signingSecrets as $signingSecret)
        {
            try
            {
                if (!isset($_SERVER['HTTP_STRIPE_SIGNATURE']))
                    throw new \Stripe\Exception\SignatureVerificationException("Webhook signature could not be found in the request headers.", 400);

                // throws SignatureVerificationException
                $event = \Stripe\Webhook::constructEvent($this->request->getContent(), $_SERVER['HTTP_STRIPE_SIGNATURE'], $signingSecret);

                $success = true;
            }
            catch(\UnexpectedValueException $e)
            {
                $key = hash('md2', $e->getMessage());
                $errors[$key] = "#" . $count++ . " " . $e->getMessage();

                throw new WebhookException("Invalid webhook payload.", 400);
            }
            catch(\Stripe\Exception\SignatureVerificationException $e)
            {
                $key = hash('md2', $e->getMessage());
                $errors[$key] = "#" . $count++ . " " . $e->getMessage();
            }
        }

        if (!$success)
        {
            $this->log("Webhook origin check failed with " . count($errors) . " errors:\n" . implode("\n", $errors));
            throw new WebhookException("Webhook origin check failed.", 400);
        }
    }

    public function cache($event)
    {
        if (empty($event['id']))
            throw new WebhookException("No event ID specified");

        // Cache for 15 days
        $this->cache->save("processed", $event['id'], array('stripe_payments_webhooks_events_processed'), 15 * 24 * 60 * 60);
    }

    // Does not throw an exception
    public function getOrderIdFromObject(array $object, $includeMultishipping = false)
    {
        // For most payment methods, the order ID is here
        if (!empty($object['metadata']['Order #']))
            return $object['metadata']['Order #'];

        // Multishipping cases
        if (!empty($object['metadata']['Multishipping']) && !empty($object['metadata']['Orders']))
        {
            $data = $object['metadata']['Orders'];
            $data = str_replace(" ", "", $data);
            $data = str_replace("#", "", $data);
            return explode(",", $data);
        }

        if ($object['object'] == 'invoice')
        {
            // For invoices created from the Magento admin
            $entry = $this->invoiceFactory->create()->load($object['id'], 'invoice_id');
            if ($entry->getOrderIncrementId())
                return $entry->getOrderIncrementId();

            // Subscriptions bought using Stripe Checkout
            foreach ($object['lines']['data'] as $lineItem)
            {
                if ($lineItem['type'] == "subscription" && !empty($lineItem['metadata']['Order #']))
                    return $lineItem['metadata']['Order #'];
            }

            // Subscriptions bought with PaymentElement
            if (!empty($object["subscription"]))
            {
                $paymentElement = $this->paymentElementFactory->create()->load($object['subscription'], 'subscription_id');
                if ($paymentElement->getOrderIncrementId())
                    return $paymentElement->getOrderIncrementId();
            }
        }
        else if ($object['object'] == 'setup_intent')
        {
            $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
            if ($paymentElement->getOrderIncrementId())
                return $paymentElement->getOrderIncrementId();
        }
        // If the merchant refunds a charge of a recurring subscription order from the Stripe dashboard, we need to drill down to the parent subscription
        else if ($object['object'] == 'charge' && !empty($object['invoice']) && !empty($object['customer']) && $this->config->reInitStripeFromCustomerId($object['customer']))
        {
            $stripe = $this->config->getStripeClient();
            $count = 2;
            $invoice = null;
            do
            {
                try
                {
                    $invoice = $stripe->invoices->retrieve($object['invoice'], ['expand' => ['subscription']]);
                }
                catch (\Exception $e)
                {
                    // Sometimes we get: This object cannot be accessed right now because another API request or Stripe process is currently accessing it.
                    sleep(1);
                }
                $count--;
            }
            while ($count > 0 && empty($invoice));

            if (!empty($invoice->subscription->metadata->{"Order #"}))
                return $invoice->subscription->metadata->{"Order #"};
        }
        else if ($object['object'] == "checkout.session")
        {
            $checkoutSessionModel = $this->checkoutSessionFactory->create()->load($object["id"], 'checkout_session_id');
            if ($checkoutSessionModel->getOrderIncrementId())
                return $checkoutSessionModel->getOrderIncrementId();
        }
        // Triggered via stripe_payments_webhook_review_closed
        else if (!empty($object['payment_intent']))
        {
            if ($includeMultishipping)
            {
                // Search for all orders which have this payment intent as a transaction ID
                $orders = $this->helper->getOrdersByTransactionId($object['payment_intent']);
                if (!empty($orders))
                {
                    $ids = [];
                    foreach ($orders as $order)
                        $ids[$order->getIncrementId()] = $order->getIncrementId();

                    return $ids;
                }
            }
            else
            {
                $paymentIntent = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
                $orderId = $paymentIntent->getOrderIncrementId();
                if ($orderId)
                    return $orderId;
            }
        }

        return null;
    }

    public function getPaymentMethod(array $object)
    {
        // Most APMs
        if (!empty($object["type"]))
            return $object["type"];

        return null;
    }

    public function loadOrderFromInvoiceId($invoiceId, $event)
    {
        $entry = $this->invoiceFactory->create()->load($invoiceId, 'invoice_id');
        if (!$entry->getOrderIncrementId())
            throw new WebhookException("We could not find the order for the invoice associated with this charge.", 202);

        return $this->loadWebhookOrderByIncrementId($entry->getOrderIncrementId(), $event);
    }

    public function loadOrderFromEvent(?array $event, $includeMultishipping = false)
    {
        if (!is_array($event))
            throw new WebhookException(__("Received invalid request payload."), 400);

        $orderId = $this->getOrderIdFromObject($event['data']['object'], $includeMultishipping);

        if (empty($orderId))
            throw new WebhookException(__("Received %1 webhook but there was no associated Order #", $event['type']), 202);

        if (is_array($orderId))
        {
            if (!$includeMultishipping)
                throw new WebhookException(__("This is a multi-shipping event that has not been implemented; ignoring."), 202);

            $orders = [];
            $orderIds = $orderId;
            foreach ($orderIds as $orderId)
                $orders[] = $this->loadWebhookOrderByIncrementId($orderId, $event);

            return $orders;
        }
        else if ($includeMultishipping)
            return [ $this->loadWebhookOrderByIncrementId($orderId, $event) ];
        else
            return $this->loadWebhookOrderByIncrementId($orderId, $event);
    }

    public function initStripeFrom($order, $event)
    {
        $paymentMethodCode = $order->getPayment()->getMethod();
        $orderId = $order->getIncrementId();
        if (strpos($paymentMethodCode, "stripe") !== 0)
            throw new WebhookException("Order #$orderId was not placed using Stripe", 202);

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        if (isset($event['data']['object']['livemode']))
            $mode = ($event['data']['object']['livemode'] ? "live" : "test");
        else
            $mode = null;
        $this->config->reInitStripe($order->getStoreId(), $order->getOrderCurrencyCode(), $mode);
        $this->webhookCollection->pong($this->config->getPublishableKey($mode));
    }

    protected function loadWebhookOrderByIncrementId($orderId, $event)
    {
        if (empty($orderId))
            throw new WebhookException(__("Ignoring %1 webhook event with no associated order ID.", $event['type']), 202);

        $order = $this->helper->loadOrderByIncrementId($orderId);

        if (empty($order) || empty($order->getId()))
            throw new WebhookException(__("Received %1 webhook with Order #%2 but could not find the order in Magento.", $event['type'], $orderId), 202);

        $this->initStripeFrom($order, $event);

        return $order;
    }

    // Called after a source.chargable event
    public function charge($order, $object, $addTransaction = true, $sendNewOrderEmail = true)
    {
        $orderId = $order->getIncrementId();

        $payment = $order->getPayment();
        if (!$payment)
            throw new WebhookException("Could not load payment method for order #$orderId");

        $orderSourceId = $payment->getAdditionalInformation('source_id');
        $webhookSourceId = $object['id'];
        if ($orderSourceId != $webhookSourceId)
            throw new WebhookException("Received source.chargeable webhook for order #$orderId but the source ID on the webhook $webhookSourceId was different than the one on the order $orderSourceId");

        $stripeParams = $this->config->getStripeParamsFrom($order);

        // Reusable sources may not have an amount set
        if (empty($object['amount']))
        {
            $amount = $stripeParams['amount'];
        }
        else
        {
            $amount = $object['amount'];
        }

        $params = array(
            "amount" => $amount,
            "currency" => $object['currency'],
            "source" => $webhookSourceId,
            "description" => $stripeParams['description'],
            "metadata" => $stripeParams['metadata']
        );

        // For reusable sources, we will always need a customer ID
        $customerStripeId = $payment->getAdditionalInformation('customer_stripe_id');
        if (!empty($customerStripeId))
            $params["customer"] = $customerStripeId;

        try
        {
            $charge = \Stripe\Charge::create($params);

            $payment->setTransactionId($charge->id);
            $payment->setLastTransId($charge->id);
            $payment->setIsTransactionClosed(0);

            // Log additional info about the payment
            $info = $this->helper->getClearSourceInfo($object[$object['type']]);
            $payment->setAdditionalInformation('source_info', json_encode($info));
            $payment->save();

            if ($addTransaction)
            {
                if (!$charge->captured)
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
                else
                    $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                //Transaction::TYPE_PAYMENT

                $transaction = $payment->addTransaction($transactionType, null, false);
                $transaction->save();
            }

            if ($charge->status == 'succeeded')
            {
                if ($charge->captured == false)
                    // $invoice = $this->helper->invoicePendingOrder($order, \Magento\Sales\Model\Order\Invoice::NOT_CAPTURE, $charge->id);
                    return;
                else
                    $invoice = $this->helper->invoiceOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order, true);
            }
            // SEPA, SOFORT and other asynchronous methods will be pending
            else if ($charge->status == 'pending')
            {
                $invoice = $this->helper->invoicePendingOrder($order, $charge->id);

                if ($sendNewOrderEmail)
                    $this->helper->sendNewOrderEmailFor($order, true);
            }
            else
            {
                // In theory we should never have failed charges because they would throw an exception
                $comment = "Authorization failed. Transaction ID: {$charge->id}. Charge status: {$charge->status}";
                $order->addStatusHistoryComment($comment);
                $this->helper->saveOrder($order);
            }

            return $charge;
        }
        catch (\Stripe\Exception\CardException $e)
        {
            $comment = "Order could not be charged because of a card error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $this->helper->saveOrder($order);
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
        catch (\Exception $e)
        {
            $comment = "Order could not be charged because of server side error: " . $e->getMessage();
            $order->addStatusHistoryComment($comment);
            $this->helper->saveOrder($order);
            $this->log($e->getMessage());
            throw new WebhookException($e->getMessage(), 202);
        }
    }

    public function getCurrentRefundFrom($webhookData)
    {
        $lastRefundDate = 0;
        $currentRefund = null;

        foreach ($webhookData['refunds']['data'] as $refund)
        {
            // There might be multiple refunds, and we are looking for the most recent one
            if ($refund['created'] > $lastRefundDate)
            {
                $lastRefundDate = $refund['created'];
                $currentRefund = $refund;
            }
        }

        return $currentRefund;
    }

    public function refundOfflineOrCancel($order)
    {
        $invoices = $order->getInvoiceCollection();
        foreach ($invoices as $invoice)
        {
            if ($invoice->canCancel())
            {
                $invoice->cancel();
                $this->helper->saveInvoice($invoice);
            }
        }

        if ($order->canCreditmemo())
        {
            foreach($order->getInvoiceCollection() as $invoice)
            {
                if ($invoice->getIsPaid())
                {
                    $creditmemo = $this->creditmemoFactory->createByOrder($order);
                    $creditmemo->setInvoice($invoice);
                    $this->creditmemoService->refund($creditmemo, true);
                }
            }
        }

        if ($order->canCancel())
        {
            $order->cancel();
        }

        $this->helper->saveOrder($order);
    }

    public function refund($order, $object)
    {
        if ($order->getState() == "holded" && $order->canUnhold())
            $order->unhold();

        // Check if the order has an invoice with the charge ID we are refunding
        $chargeId = $object['id'];
        $chargeAmount = $object['amount'];
        $currentRefund = $this->getCurrentRefundFrom($object);
        $currency = $currentRefund['currency'];
        $baseToOrderRate = $order->getBaseToOrderRate();
        $payment = $order->getPayment();
        if (isset($object["payment_intent"]))
            $pi = $object["payment_intent"];
        else
            $pi = "not_exists";

        // Calculate the real refund amount
        $isMultiCurrencyRefund = ($currentRefund['currency'] != $order->getOrderCurrencyCode());
        $refundAmount = $this->helper->convertStripeAmountToOrderAmount($currentRefund['amount'], $currentRefund['currency'], $order);
        $baseRefundAmount = $this->helper->convertStripeAmountToBaseOrderAmount($currentRefund['amount'], $currentRefund['currency'], $order);

        $baseTotalNotRefunded = $order->getBaseGrandTotal() - $order->getBaseTotalRefunded();
        $totalNotRefunded = $order->getGrandTotal() - $order->getTotalRefunded();

        if ($isMultiCurrencyRefund)
            $isPartialRefund = ($totalNotRefunded > $refundAmount);
        else
            $isPartialRefund = ($baseTotalNotRefunded > $baseRefundAmount);

        if (!$order->canCreditmemo())
        {
            if ($order->canCancel())
            {
                if (!$isPartialRefund)
                {
                    $order->cancel();
                    $this->helper->saveOrder($order);
                    return true;
                }
                else if ($isPartialRefund)
                {
                    // Don't do anything on a partial refund, we expect a paynemt_intent.succeeded to arrive for the partial capture.
                    return false;
                }
            }
            else if (!$isPartialRefund)
            {
                $invoices = $order->getInvoiceCollection();
                $canceled = 0;
                foreach ($invoices as $invoice)
                {
                    if ($invoice->canCancel())
                    {
                        $invoice->cancel();
                        $this->helper->saveInvoice($invoice);
                        $canceled++;
                    }
                }
                if ($canceled > 0)
                {
                    if ($order->canCancel())
                    {
                        $order->getPayment()->setCancelOfflineWithComment(__("The authorization was canceled via Stripe."));
                        $order->cancel();
                    }

                    $this->helper->saveOrder($order);
                    return true;
                }
            }

            $msg = __('A refund was issued via Stripe, but a Credit Memo could not be created.');
            $this->helper->addOrderComment($msg, $order);
            $this->helper->saveOrder($order);
            return false;
        }

        if ($baseTotalNotRefunded < $baseRefundAmount)
        {
            $humanReadable1 = $this->helper->addCurrencySymbol($refundAmount, $currency);
            $humanReadable2 = $this->helper->addCurrencySymbol($totalNotRefunded, $currency);
            $msg = __('A refund of %1 was issued via Stripe, but the amount is bigger than the available of %2.', $humanReadable1, $humanReadable2);
            $this->helper->addOrderComment($msg, $order);
            $this->helper->saveOrder($order);
            return false;
        }
        else
        {
            $creditmemo = $this->creditmemoFactory->createByOrder($order);
            $baseDiff = $baseTotalNotRefunded - $baseRefundAmount;

            if ($isPartialRefund)
            {
                // We don't have any information from Stripe on what products we are refunding
                $creditmemo->setItems([]);
                $creditmemo->setShippingAmount(0);
                $creditmemo->setAdjustmentPositive($baseDiff);
                $creditmemo->setAdjustmentNegative(0);
            }
            else
            {
                $creditmemo->setItems($order->getAllItems());
                $creditmemo->setShippingAmount($order->getShippingAmount());
                $creditmemo->setAdjustmentPositive(0);
                $creditmemo->setAdjustmentNegative(0);
            }
        }

        $invoice = $this->getInvoiceWithTransactionId($chargeId, $order);

        if (!$invoice)
            $invoice = $this->getInvoiceWithTransactionId($pi, $order);

        if ($invoice)
            $creditmemo->setInvoice($invoice);

        $creditmemo->setBaseSubtotal(0);
        $creditmemo->setSubtotal(0);
        $creditmemo->setBaseGrandTotal($baseRefundAmount);
        $creditmemo->setGrandTotal($refundAmount);

        $this->creditmemoService->refund($creditmemo, true);

        $comment = __("We refunded %1 through Stripe.", $this->helper->addCurrencySymbol($refundAmount, $currency));
        $order->addStatusToHistory($status = false, $comment);

        $this->helper->saveOrder($order);
        $this->helper->saveCreditmemo($creditmemo);
        $this->helper->savePayment($payment);

        return true;
    }

    public function getInvoiceWithTransactionId($transactionId, $order)
    {
        foreach($order->getInvoiceCollection() as $item)
        {
            $invoiceTransactionId = $this->helper->cleanToken($item->getTransactionId());
            if ($transactionId == $invoiceTransactionId)
                return $item;
        }

        return null;
    }

    public function removeEndpoint()
    {
        $url = $this->urlInterface->getCurrentUrl();
        $endpoints = \Stripe\WebhookEndpoint::all();
        foreach ($endpoints as $endpoint)
        {
            if (strpos($url, $endpoint->url) === 0)
            {
                $endpoint = \Stripe\WebhookEndpoint::retrieve($endpoint->id);
                $endpoint->delete();
            }
        }
    }

    public function sendRecurringOrderFailedEmail($eventArray, $exception)
    {
        $generalName = $this->emailHelper->getName('general');
        $generalEmail = $this->emailHelper->getEmail('general');

        if ($eventArray['livemode'])
            $mode = '';
        else
            $mode = 'test/';

        $object = $eventArray['data']['object'];

        $templateVars = [
            'paymentLink' => "https://dashboard.stripe.com/{$mode}payments/" . $object["payment_intent"],
            'subscriptionLink' => "https://dashboard.stripe.com/{$mode}subscriptions/" . $object["subscription"],
            'customerLink' => "https://dashboard.stripe.com/{$mode}customers/" . $object["customer"],
            'errorMessage' => $exception->getMessage(),
            'stackTrace' => $exception->getTraceAsString(),
            'eventLink' => "https://dashboard.stripe.com/{$mode}events/" . $eventArray["id"]
        ];

        $this->emailHelper->send('stripe_failed_recurring_order', $generalName, $generalEmail, $generalName, $generalEmail, $templateVars);
    }
}
