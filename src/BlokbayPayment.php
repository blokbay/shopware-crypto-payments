<?php declare(strict_types=1);

namespace Blokbay\Payment;

use Blokbay\Payment\Handler\BlokbayPaymentHandler;
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

    /** @var array<string, array{name: string, description: string}> */
    private const TEXTS = [
        'en-GB' => ['name' => 'Crypto (BTC, USDC, USDT, ETH)', 'description' => 'Pay with your crypto wallet on the secure Blokbay payment page.'],
        'de-DE' => ['name' => 'Krypto (BTC, USDC, USDT, ETH)', 'description' => 'Bezahlen Sie mit Ihrem Krypto-Wallet auf der sicheren Blokbay-Zahlungsseite.'],
        'it-IT' => ['name' => 'Crypto (BTC, USDC, USDT, ETH)', 'description' => 'Paga con il tuo wallet crypto sulla pagina di pagamento sicura di Blokbay.'],
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
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->setActive(true, $activateContext->getContext());
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
