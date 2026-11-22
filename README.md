# Shopware 6 Crypto Payment Gateway: Bitcoin, USDT, USDC, ETH (Blokbay)

Accept crypto payments in Shopware 6: Bitcoin (BTC), Tether (USDT), USD Coin (USDC) and Ether (ETH) on Ethereum, BNB Smart Chain (BSC), Polygon, Base and Arbitrum. Non-custodial: every payment goes straight to your own wallet. 1% per payment, no monthly fee. Download the latest release: [Releases](../../releases/latest).

Accept BTC, USDC, USDT and ETH in your Shopware 6.7 shop. The customer pays on the Blokbay payment page and the funds go straight
to your own wallet: no custody, no frozen funds, no waiting for a payout. 1% of the paid price is withheld automatically, no fixed costs.
You remain responsible for your shop and for your obligations towards your customers.

## Setup
1. Open a free account on https://blokbay.com, add your wallet, then open "Payments from your site".
2. Install and activate the plugin (Extensions > My extensions, or `bin/console plugin:install --activate BlokbayPayment`).
3. In the plugin settings paste the secret API key (sk_live_...), the webhook signing secret (whsec_...) and, optionally, the account (pk_live_...).
4. In the Blokbay dashboard set the webhook URL to `https://YOUR-SHOP/api/_action/blokbay/webhook` and send the test event (the shop answers "pong").
5. Add the payment method "Crypto (BTC, USDC, USDT, ETH)" to your sales channel (Sales Channels > Payment methods).

Orders must be in USD. Bitcoin is available from 100 USD; stablecoins and ETH from 1 USD.

## How it works
- At checkout the plugin creates a Blokbay payment (with an Idempotency-Key) and sends the customer to the payment page.
- If the payment cannot be created, Shopware marks the transaction as failed and the customer lands on the order page to pay again.
- When paid, Blokbay sends a signed `payment.paid` webhook (HMAC-SHA256, 5-minute tolerance) and the transaction becomes "Paid".
  The same event delivered twice changes nothing. When the customer comes back, the plugin also asks Blokbay for the status.
- `payment.expired` cancels the transaction only if it is still the current payment of an unpaid transaction.

---

# Blokbay Krypto-Zahlungen für Shopware 6 (Deutsch)

Akzeptieren Sie BTC, USDC, USDT und ETH in Ihrem Shopware-6.7-Shop. Der Kunde zahlt auf der Blokbay-Zahlungsseite, das Geld geht direkt
in Ihr eigenes Wallet: keine Verwahrung, keine eingefrorenen Gelder, keine Wartezeit auf eine Auszahlung. 1 % des gezahlten Preises wird
automatisch einbehalten, keine Fixkosten. Sie bleiben für Ihren Shop und Ihre Pflichten gegenüber Ihren Kunden verantwortlich.

Einrichtung: Konto auf https://blokbay.com eröffnen und Wallet hinterlegen; Plugin installieren und aktivieren; geheimen API-Schlüssel,
Webhook-Geheimnis und optional das Konto (pk_live_...) eintragen; im Blokbay-Dashboard die Webhook-URL
`https://IHR-SHOP/api/_action/blokbay/webhook` setzen; die Zahlungsart dem Verkaufskanal zuweisen. Bestellungen in USD.

---

# Pagamenti crypto Blokbay per Shopware 6 (Italiano)

Accetta BTC, USDC, USDT ed ETH nel tuo negozio Shopware 6.7. Il cliente paga sulla pagina di pagamento di Blokbay e i fondi vanno
direttamente nel tuo wallet: nessuna custodia, nessun fondo bloccato, nessuna attesa di accredito. L'1% del prezzo pagato è trattenuto
in automatico, nessun costo fisso. Resti responsabile del tuo negozio e dei tuoi obblighi verso i clienti.

Configurazione: apri un account su https://blokbay.com e aggiungi il wallet; installa e attiva il plugin; inserisci chiave API segreta,
segreto del webhook ed eventualmente l'account (pk_live_...); nel pannello Blokbay imposta l'URL del webhook
`https://IL-TUO-NEGOZIO/api/_action/blokbay/webhook`; assegna il metodo di pagamento al canale di vendita. Ordini in USD.

## Supported networks
Payments settle on chain through the verified LuxSplit gateway contract `0xd4b637cC81D5B5115Cd0204A9ba6B60474F05e31` (same address on every network, source verified on Sourcify):

| Network | Chain ID | Assets |
|---|---|---|
| BNB Smart Chain (BSC, BNB Chain) | 56 | USDC, USDT |
| Ethereum | 1 | ETH, USDC, USDT |
| Polygon | 137 | USDC, USDT |
| Base | 8453 | USDC |
| Arbitrum | 42161 | USDC, USDT |
| Bitcoin | – | BTC (orders from 100 USD) |

On BNB Smart Chain the buyer pays with USDC or USDT (BEP-20) and the funds reach the merchant's wallet in the same transaction.
Verified source (Sourcify, chain 56): https://repo.sourcify.dev/56/0xd4b637cC81D5B5115Cd0204A9ba6B60474F05e31
The network list is also in [`networks.json`](networks.json).

