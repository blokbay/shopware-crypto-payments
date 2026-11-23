<?php declare(strict_types=1);

namespace Blokbay\Payment\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Shared logic of the webhook and of the customer's return: finds the order transaction of a Blokbay payment
 * (exact match on the payment id, never "the latest Blokbay order") and moves it to paid or cancelled.
 */
class PaymentUpdater
{
    public const CF_ID = 'blokbay_payment_id';
    public const CF_AMOUNT = 'blokbay_amount';
    public const CF_CURRENCY = 'blokbay_currency';
    public const CF_PREV = 'blokbay_prev_ids';
    private const CONFIG = 'BlokbayPayment.config.';

    /**
     * @param EntityRepository<OrderTransactionCollection> $transactionRepository
     */
    public function __construct(
        private readonly EntityRepository $transactionRepository,
        private readonly OrderTransactionStateHandler $stateHandler,
        private readonly SystemConfigService $config,
    ) {
    }

    public function config(string $key, ?string $salesChannelId): string
    {
        $v = $this->config->get(self::CONFIG . $key, $salesChannelId);

        return \is_string($v) ? trim($v) : '';
    }

    public function api(?string $salesChannelId): BlokbayApi
    {
        $base = $this->config('apiBase', $salesChannelId);

        return new BlokbayApi($this->config('secretKey', $salesChannelId), $base !== '' ? $base : BlokbayApi::DEFAULT_BASE);
    }

    /**
     * The transaction whose current Blokbay payment is $paymentId, or an earlier attempt of the same transaction.
     *
     * @return array{0: OrderTransactionEntity, 1: bool, 2: string}|null [transaction, is current payment, amount sent to Blokbay]
     */
    public function find(string $paymentId, Context $context): ?array
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', $paymentId)) {
            return null;
        }
        $c = (new Criteria())->addFilter(new EqualsFilter('customFields.' . self::CF_ID, $paymentId))->addAssociation('stateMachineState')->addAssociation('order');
        $tx = $this->transactionRepository->search($c, $context)->getEntities()->first();
        if ($tx instanceof OrderTransactionEntity) {
            $cf = $tx->getCustomFields() ?? [];

            return [$tx, true, (string) ($cf[self::CF_AMOUNT] ?? '')];
        }
        // Earlier attempts are kept as "gp_a=12.00,gp_b=12.00": LIKE narrows the search, the exact match is checked here.
        $c = (new Criteria())->addFilter(new ContainsFilter('customFields.' . self::CF_PREV, $paymentId . '='))->addAssociation('stateMachineState')->addAssociation('order');
        $candidates = $this->transactionRepository->search($c, $context)->getEntities();
        foreach ($candidates as $tx) {
            $cf = $tx->getCustomFields() ?? [];
            foreach (explode(',', (string) ($cf[self::CF_PREV] ?? '')) as $pair) {
                $kv = explode('=', $pair, 2);
                if (\count($kv) === 2 && $kv[0] === $paymentId) {
                    return [$tx, false, $kv[1]];
                }
            }
        }

        return null;
    }

    /**
     * Applies a Blokbay payment object (webhook "data" or GET /api/v1/payments/:id) to its order transaction.
     *
     * @param array<string, mixed> $p
     */
    public function apply(array $p, Context $context): string
    {
        $found = $this->find(\is_string($p['id'] ?? null) ? $p['id'] : '', $context);
        if ($found === null) {
            return 'unknown payment';
        }
        [$tx, $current, $amount] = $found;
        $state = $tx->getStateMachineState()?->getTechnicalName() ?? '';
        $status = (string) ($p['status'] ?? '');
        if ($status === 'paid') {
            // Amount and currency as sent for this order (transactions created before 1.1.0 have no currency field: USD).
            $currency = (string) (($tx->getCustomFields() ?? [])[self::CF_CURRENCY] ?? 'USD');
            if ($amount === '' || $amount !== (string) ($p['amount'] ?? '') || ($p['currency'] ?? '') !== $currency) {
                return 'amount mismatch';
            }
            if ($state === OrderTransactionStates::STATE_PAID) {
                return 'already paid';
            }
            $this->markPaid($tx->getId(), $context);
            $this->transactionRepository->update([[
                'id' => $tx->getId(),
                'customFields' => array_merge($tx->getCustomFields() ?? [], ['blokbay_tx' => substr((string) ($p['tx'] ?? ''), 0, 120), 'blokbay_network' => (string) ($p['network'] ?? ''), 'blokbay_coin' => (string) ($p['coin'] ?? '')]),
            ]], $context);

            return 'paid';
        }
        // Expiry only for the payment that is still the current one of an unpaid transaction: an old attempt never cancels a new one.
        if ($status === 'expired' && $current && \in_array($state, [OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_UNCONFIRMED, OrderTransactionStates::STATE_IN_PROGRESS], true)) {
            try {
                $this->stateHandler->cancel($tx->getId(), $context);
            } catch (StateMachineException) {
                return 'ok';
            }

            return 'expired';
        }

        return 'ok';
    }

    private function markPaid(string $transactionId, Context $context): void
    {
        try {
            $this->stateHandler->paid($transactionId, $context);
        } catch (StateMachineException) {
            // Cancelled or failed (customer left, then paid on the Blokbay page): reopen first. The funds are already with the merchant.
            $this->stateHandler->reopen($transactionId, $context);
            $this->stateHandler->paid($transactionId, $context);
        }
    }
}
