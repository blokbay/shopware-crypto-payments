<?php declare(strict_types=1);

namespace Blokbay\Payment\Handler;

use Blokbay\Payment\Service\BlokbayApi;
use Blokbay\Payment\Service\PaymentUpdater;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\StateMachine\StateMachineException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Redirect payment: pay() creates the Blokbay payment and sends the customer to the Blokbay payment page;
 * finalize() runs when the customer comes back and asks Blokbay for the real status (the webhook may not have arrived yet).
 */
class BlokbayPaymentHandler extends AbstractPaymentHandler
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $transactionRepository
     */
    public function __construct(
        private readonly EntityRepository $transactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly PaymentUpdater $updater,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false; // no refunds or recurring payments: crypto payments go straight to the merchant's wallet.
    }

    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        $txId = $transaction->getOrderTransactionId();
        $tx = $this->load($txId, $context);
        $order = $tx->getOrder();
        if ($order === null) {
            throw PaymentException::asyncProcessInterrupted($txId, 'Order not found.');
        }
        $iso = strtoupper($order->getCurrency()?->getIsoCode() ?? '');
        if (!BlokbayApi::supported($iso)) {
            throw PaymentException::asyncProcessInterrupted($txId, 'Blokbay accepts orders in ' . implode(', ', array_keys(BlokbayApi::CURRENCIES)) . ' (order currency: ' . $iso . ').');
        }
        $returnUrl = (string) $transaction->getReturnUrl();
        $amount = BlokbayApi::amount($tx->getAmount()->getTotalPrice(), $iso);
        $cf = $tx->getCustomFields() ?? [];
        $prev = trim((string) ($cf[PaymentUpdater::CF_PREV] ?? ''), ',');
        if (!empty($cf[PaymentUpdater::CF_ID])) {
            $prev = ltrim($prev . ',' . $cf[PaymentUpdater::CF_ID] . '=' . ($cf[PaymentUpdater::CF_AMOUNT] ?? ''), ',');
        }
        $attempt = $prev === '' ? 0 : \count(explode(',', $prev));
        $number = (string) $order->getOrderNumber();
        $res = $this->updater->api($order->getSalesChannelId())->createPayment([
            'amount' => $amount,
            'currency' => $iso,
            'reference' => $number,
            'description' => 'Order ' . $number,
            'customer_email' => (string) $order->getOrderCustomer()?->getEmail(),
            'url_success' => $returnUrl,
            'url_cancel' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'blokbay_cancel=1',
        ], 'sw-' . $txId . '-' . $amount . ($iso === 'USD' ? '' : '-' . $iso) . '-' . $attempt);
        if (empty($res['id']) || empty($res['url']) || !\is_string($res['id']) || !\is_string($res['url'])) {
            $err = \is_string($res['error'] ?? null) ? $res['error'] : 'error';
            $this->logger->error('Blokbay: payment not created (' . $err . ')', ['orderNumber' => $number]);
            // Shopware marks the transaction failed and sends the customer to the order page to pay again.
            throw PaymentException::asyncProcessInterrupted($txId, 'The crypto payment could not be created (' . $err . '). Please try again.');
        }
        $this->transactionRepository->update([[
            'id' => $txId,
            'customFields' => array_merge($cf, [PaymentUpdater::CF_ID => $res['id'], PaymentUpdater::CF_AMOUNT => $amount, PaymentUpdater::CF_CURRENCY => $iso, PaymentUpdater::CF_PREV => $prev]),
        ]], $context);

        return new RedirectResponse($res['url']);
    }

    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        $txId = $transaction->getOrderTransactionId();
        $tx = $this->load($txId, $context);
        $id = (string) (($tx->getCustomFields() ?? [])[PaymentUpdater::CF_ID] ?? '');
        $p = $id !== '' ? $this->updater->api($tx->getOrder()?->getSalesChannelId())->getPayment($id) : [];
        $status = \is_string($p['status'] ?? null) && ($p['id'] ?? '') === $id ? $p['status'] : '';
        if ($status === 'paid') {
            $this->updater->apply($p, $context);

            return;
        }
        if ($request->query->getBoolean('blokbay_cancel') || $status === 'expired') {
            throw PaymentException::customerCanceled($txId, 'Crypto payment not completed.');
        }
        // Still pending (the wallet transaction needs confirmations) or Blokbay not reachable: the webhook will settle it.
        if (($tx->getStateMachineState()?->getTechnicalName() ?? '') === OrderTransactionStates::STATE_OPEN) {
            try {
                $this->stateHandler->processUnconfirmed($txId, $context);
            } catch (StateMachineException) {
                // leave it open
            }
        }
    }

    private function load(string $txId, Context $context): OrderTransactionEntity
    {
        $c = (new Criteria([$txId]))->addAssociation('order.currency')->addAssociation('order.orderCustomer')->addAssociation('stateMachineState');
        $tx = $this->transactionRepository->search($c, $context)->getEntities()->first();
        if (!$tx instanceof OrderTransactionEntity) {
            throw PaymentException::invalidTransaction($txId);
        }

        return $tx;
    }
}
