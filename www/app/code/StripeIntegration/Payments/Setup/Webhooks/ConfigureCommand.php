<?php

namespace StripeIntegration\Payments\Setup\Webhooks;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Command
{
    protected function configure()
    {
        $this->setName('stripe:webhooks:configure');
        $this->setDescription('Deletes and re-creates all webhook endpoints in configured Stripe accounts.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $areaCode = $objectManager->create('StripeIntegration\Payments\Helper\AreaCode');
        $areaCode->setAreaCode();

        $webhooksSetup = $objectManager->create('StripeIntegration\Payments\Helper\WebhooksSetup');
        $exitCode = $webhooksSetup->configure($output);

        foreach ($webhooksSetup->successMessages as $successMessage)
        {
            $output->writeln("<info>{$successMessage}</info>");
        }

        if (count($webhooksSetup->errorMessages))
        {
            foreach ($webhooksSetup->errorMessages as $errorMessage)
            {
                $output->writeln("<error>{$errorMessage}</error>");
            }

            return 1;
        }

        return $exitCode;
    }
}
