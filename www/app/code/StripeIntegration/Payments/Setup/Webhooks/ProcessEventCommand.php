<?php

namespace StripeIntegration\Payments\Setup\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessEventCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:webhooks:process-event');
        $this->setDescription('Process or resend a webhook event which Stripe failed to deliver.');
        $this->addArgument('event_id', \Symfony\Component\Console\Input\InputArgument::REQUIRED);
        $this->addOption("force", 'f', InputOption::VALUE_NONE, 'Force process even if the event was already sent and processed.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $eventId = $input->getArgument("event_id");
        $force = $input->getOption("force");

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $config = $objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $webhookEventHelper = $objectManager->get(\StripeIntegration\Payments\Helper\WebhookEvent::class);
        $webhooks = $objectManager->get(\StripeIntegration\Payments\Helper\Webhooks::class);
        $cache = $objectManager->get(\Magento\Framework\App\CacheInterface::class);

        $event = $webhookEventHelper->getEvent($eventId);
        if (empty($event))
        {
            $output->writeln("<error>Event not found.</error>");
            return 1;
        }

        if ($force)
        {
            $cache->remove($event->id);
        }

        $output->writeln(">>> Processing event $eventId");
        $webhooks->setOutput($output);
        $webhooks->dispatchEvent($event);

        return 0;
    }
}
