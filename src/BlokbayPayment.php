<?php declare(strict_types=1);

namespace Blokbay\Payment;

use Blokbay\Payment\Handler\BlokbayPaymentHandler;
use Blokbay\Payment\Service\BlokbayApi;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\Language\LanguageCollection;

class BlokbayPayment extends Plugin
{
    public const TECHNICAL_NAME = 'payment_blokbay';

    /** @var array<string, array{name: string, description: string}> Used only when the payment method is created (first install): names and descriptions edited by the merchant are never overwritten. */
    private const TEXTS = [
        'en-GB' => ['name' => 'Crypto (USDT, USDC, ETH, BTC, SOL)', 'description' => 'Pay in crypto: USDT, USDC, ETH, BTC or SOL, from your wallet or an exchange'],
        'de-DE' => ['name' => 'Krypto (USDT, USDC, ETH, BTC, SOL)', 'description' => 'Mit Krypto bezahlen: USDT, USDC, ETH, BTC oder SOL, aus Ihrem Wallet oder von einer Börse'],
        'it-IT' => ['name' => 'Crypto (USDT, USDC, ETH, BTC, SOL)', 'description' => 'Paga in crypto: USDT, USDC, ETH, BTC o SOL, dal tuo wallet o da un exchange'],
    ];

    public function install(InstallContext $installContext): void
    {
        $context = $installContext->getContext();
        if ($this->paymentMethodId($context) !== null) {
            return;
        }
        $pluginId = $this->container?->get(PluginIdProvider::class);
        if (!$pluginId instanceof PluginIdProvider) {
            return;
        }
        $translations = [];
        foreach ($this->locales($context) as $code) {
            $translations[$code] = self::TEXTS[$code];
        }
        $this->paymentRepository()->create([[
            'handlerIdentifier' => BlokbayPaymentHandler::class,
            'technicalName' => self::TECHNICAL_NAME,
            'name' => self::TEXTS['en-GB']['name'],
            'description' => self::TEXTS['en-GB']['description'],
            'pluginId' => $pluginId->getPluginIdByBaseClass(static::class, $context),
            'afterOrderEnabled' => true,
            'active' => false,
            'translations' => $translations,
        ]], $context);
        $this->syncCurrencyRule($context);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->syncCurrencyRule($updateContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->setActive(true, $activateContext->getContext());
        $this->syncCurrencyRule($activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        // The payment method stays (existing orders refer to it), switched off.
        $this->setActive(false, $uninstallContext->getContext());
    }

    private function setActive(bool $active, Context $context): void
    {
        $id = $this->paymentMethodId($context);
        if ($id !== null) {
            $this->paymentRepository()->update([['id' => $id, 'active' => $active]], $context);
        }
    }

    /**
     * Availability rule of the payment method: shown only when the cart currency is accepted by Blokbay (USD, EUR, GBP, CHF,
     * JPY, CAD, AUD, SGD, HKD, AED). If the default currency of the shop is not accepted, a notice in the administration.
     * Run on install, activate and update (new currencies added later: run bin/console plugin:update or reactivate).
     */
    private function syncCurrencyRule(Context $context): void
    {
        $methodId = $this->paymentMethodId($context);
        $currencies = $this->container?->get('currency.repository');
        $rules = $this->container?->get('rule.repository');
        if ($methodId === null || !$currencies instanceof EntityRepository || !$rules instanceof EntityRepository) {
            return;
        }
        $ok = [];
        $defaultIso = '';
        foreach ($currencies->search(new Criteria(), $context)->getEntities() as $cur) {
            $iso = strtoupper((string) $cur->getIsoCode());
            if ($cur->getId() === Defaults::CURRENCY) {
                $defaultIso = $iso;
            }
            if (BlokbayApi::supported($iso)) {
                $ok[] = $cur->getId();
            }
        }
        $ruleId = md5('blokbay-payment-currency-rule');
        if ($ok !== []) {
            $rules->upsert([[
                'id' => $ruleId,
                'name' => 'Blokbay: currencies accepted by Blokbay',
                'priority' => 1,
                'description' => 'Created by the Blokbay plugin: the crypto payment method is shown only for these currencies.',
                'conditions' => [[
                    'id' => md5('blokbay-payment-currency-condition'),
                    'type' => 'currency',
                    'value' => ['operator' => '=', 'currencyIds' => $ok],
                ]],
            ]], $context);
            $this->paymentRepository()->update([['id' => $methodId, 'availabilityRuleId' => $ruleId]], $context);
        }
        if ($defaultIso !== '' && !BlokbayApi::supported($defaultIso)) {
            $notes = $this->container?->get('notification.repository');
            if ($notes instanceof EntityRepository) {
                try {
                    $notes->create([[
                        'status' => 'warning',
                        'message' => 'Blokbay accepts prices in ' . implode(', ', array_keys(BlokbayApi::CURRENCIES)) . '. The default currency of this shop is ' . $defaultIso . ': the crypto payment method stays hidden for carts in that currency.',
                        'adminOnly' => true,
                    ]], $context);
                } catch (\Throwable $e) {
                    // notice only
                }
            }
        }
    }

    private function paymentMethodId(Context $context): ?string
    {
        $c = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', BlokbayPaymentHandler::class));

        return $this->paymentRepository()->searchIds($c, $context)->firstId();
    }

    /** @return EntityRepository<PaymentMethodCollection> */
    private function paymentRepository(): EntityRepository
    {
        /** @var EntityRepository<PaymentMethodCollection> $r */
        $r = $this->container?->get('payment_method.repository');

        return $r;
    }

    /** @return list<string> locale codes of TEXTS that have a language in this shop (en-GB always) */
    private function locales(Context $context): array
    {
        /** @var EntityRepository<LanguageCollection> $langs */
        $langs = $this->container?->get('language.repository');
        $c = (new Criteria())->addAssociation('locale');
        $out = ['en-GB'];
        $languages = $langs->search($c, $context)->getEntities();
        foreach ($languages as $lang) {
            $code = $lang->getLocale()?->getCode();
            if (\is_string($code) && isset(self::TEXTS[$code]) && !\in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }
}
