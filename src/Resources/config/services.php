<?php declare(strict_types=1);

use Blokbay\Payment\Controller\WebhookController;
use Blokbay\Payment\Handler\BlokbayPaymentHandler;
use Blokbay\Payment\Service\PaymentUpdater;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(PaymentUpdater::class)
        ->args([
            service('order_transaction.repository'),
            service('Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler'),
            service('Shopware\Core\System\SystemConfig\SystemConfigService'),
        ]);

    $services->set(BlokbayPaymentHandler::class)
        ->args([
            service('order_transaction.repository'),
            service('Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler'),
            service(PaymentUpdater::class),
            service('logger'),
        ])
        ->tag('shopware.payment.method');

    $services->set(WebhookController::class)
        ->public()
        ->args([
            service(PaymentUpdater::class),
            service('sales_channel.repository'),
        ]);
};
