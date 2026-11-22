<?php declare(strict_types=1);

namespace Blokbay\Payment\Controller;

use Blokbay\Payment\Service\BlokbayApi;
use Blokbay\Payment\Service\PaymentUpdater;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook endpoint for Blokbay: POST https://<your-shop>/api/_action/blokbay/webhook
 * JSON body, header Blokbay-Signature "t=<unix>,v1=<HMAC-SHA256>". No login: the signature is the authentication.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class WebhookController
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly PaymentUpdater $updater,
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    #[Route(path: '/api/_action/blokbay/webhook', name: 'api.action.blokbay.webhook', defaults: ['auth_required' => false], methods: ['POST'])]
    public function webhook(Request $request, Context $context): Response
    {
        $raw = (string) $request->getContent();
        $sig = (string) $request->headers->get('Blokbay-Signature', '');
        $ev = json_decode($raw, true);
        if (!\is_array($ev) || !\is_string($ev['type'] ?? null)) {
            return $this->reply(400, 'bad payload');
        }
        $data = \is_array($ev['data'] ?? null) ? $ev['data'] : [];
        $found = \is_string($data['id'] ?? null) ? $this->updater->find($data['id'], $context) : null;
        // Secret and public key of the sales channel of the order (Shopware falls back to the global value).
        $channels = $found !== null ? [$found[0]->getOrder()?->getSalesChannelId()] : $this->channels($context);
        $ok = false;
        $pk = '';
        foreach ($channels as $ch) {
            if (BlokbayApi::verifySignature($raw, $sig, $this->updater->config('webhookSecret', $ch))) {
                $ok = true;
                $pk = $this->updater->config('publicKey', $ch);
                break;
            }
        }
        if (!$ok) {
            return $this->reply(400, 'bad signature');
        }
        if ($pk !== '' && ($ev['account'] ?? null) !== $pk) {
            return $this->reply(400, 'other account');
        }
        if ($ev['type'] === 'ping') {
            return $this->reply(200, 'pong');
        }
        if ($found === null) {
            return $this->reply(200, 'unknown payment');
        }

        return $this->reply(200, $this->updater->apply($data, $context));
    }

    /** @return list<string|null> null = global configuration, then each sales channel */
    private function channels(Context $context): array
    {
        $ids = $this->salesChannelRepository->searchIds(new Criteria(), $context)->getIds();
        $out = [null];
        foreach ($ids as $id) {
            $out[] = $id;
        }

        return $out;
    }

    private function reply(int $code, string $msg): Response
    {
        return new Response($msg, $code, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
