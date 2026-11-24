# Blokbay crypto payments for Shopware 6

Accept USDT, USDC, ETH, BTC and SOL in your Shopware 6.7 shop. The customer pays on the Blokbay payment page and the funds go straight
to your own wallet: no custody, no frozen funds, no waiting for a payout. 1% of the paid price is withheld automatically, no fixed costs.
You remain responsible for your shop and for your obligations towards your customers.

Coins and networks on the payment page: USDT and USDC on Ethereum, BNB Smart Chain, Arbitrum, Polygon and Solana; USDC on Base; ETH on Ethereum, Base and Arbitrum; SOL on Solana; Bitcoin. The customer pays from a connected wallet, or by address and QR code from any wallet or exchange, and can change coin and network before paying.

## Setup
1. Open a free account on https://blokbay.com, add your wallet, then open "Payments from your site".
2. Install and activate the plugin (Extensions > My extensions, or `bin/console plugin:install --activate BlokbayPayment`).
3. In the plugin settings paste the secret API key (sk_live_...), the webhook signing secret (whsec_...) and, optionally, the account (pk_live_...).
4. In the Blokbay dashboard set the webhook URL to `https://YOUR-SHOP/api/_action/blokbay/webhook` and send the test event (the shop answers "pong").
5. Add the payment method "Crypto (USDT, USDC, ETH, BTC, SOL)" to your sales channel (Sales Channels > Payment methods). In shops that installed an earlier version it keeps its name, "Crypto (BTC, USDC, USDT, ETH)" or the one you gave it.

Order currencies: USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD, AED (the customer pays in crypto at the current exchange rate). The plugin adds the availability rule "Blokbay: currencies accepted by Blokbay", so the method is hidden for other currencies, and shows a notice in the administration if the default currency is not accepted. Bitcoin is available from the equivalent of 100 USD; the other coins from the equivalent of 1 USD.

## How it works
- At checkout the plugin creates a Blokbay payment (with an Idempotency-Key) and sends the customer to the payment page.
- If the payment cannot be created, Shopware marks the transaction as failed and the customer lands on the order page to pay again.
- When paid, Blokbay sends a signed `payment.paid` webhook (HMAC-SHA256, 5-minute tolerance) and the transaction becomes "Paid".
  The same event delivered twice changes nothing. When the customer comes back, the plugin also asks Blokbay for the status.
- `payment.expired` cancels the transaction only if it is still the current payment of an unpaid transaction.

---

# Blokbay Krypto-Zahlungen für Shopware 6 (Deutsch)

Akzeptieren Sie USDT, USDC, ETH, BTC und SOL in Ihrem Shopware-6.7-Shop. Der Kunde zahlt auf der Blokbay-Zahlungsseite, das Geld geht direkt
in Ihr eigenes Wallet: keine Verwahrung, keine eingefrorenen Gelder, keine Wartezeit auf eine Auszahlung. 1 % des gezahlten Preises wird
automatisch einbehalten, keine Fixkosten. Sie bleiben für Ihren Shop und Ihre Pflichten gegenüber Ihren Kunden verantwortlich.

Münzen und Netzwerke: USDT und USDC auf Ethereum, BNB Smart Chain, Arbitrum, Polygon und Solana; USDC auf Base; ETH auf Ethereum, Base und Arbitrum; SOL auf Solana; Bitcoin. Der Kunde zahlt mit einem verbundenen Wallet oder per Adresse und QR-Code aus jedem Wallet oder von jeder Börse und kann Münze und Netzwerk vor der Zahlung wechseln.

Einrichtung: Konto auf https://blokbay.com eröffnen und Wallet hinterlegen; Plugin installieren und aktivieren; geheimen API-Schlüssel,
Webhook-Geheimnis und optional das Konto (pk_live_...) eintragen; im Blokbay-Dashboard die Webhook-URL
`https://IHR-SHOP/api/_action/blokbay/webhook` setzen; die Zahlungsart dem Verkaufskanal zuweisen. Bestellungen in USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD, AED.

---

# Pagamenti crypto Blokbay per Shopware 6 (Italiano)

Accetta USDT, USDC, ETH, BTC e SOL nel tuo negozio Shopware 6.7. Il cliente paga sulla pagina di pagamento di Blokbay e i fondi vanno
direttamente nel tuo wallet: nessuna custodia, nessun fondo bloccato, nessuna attesa di accredito. L'1% del prezzo pagato è trattenuto
in automatico, nessun costo fisso. Resti responsabile del tuo negozio e dei tuoi obblighi verso i clienti.

Monete e reti: USDT e USDC su Ethereum, BNB Smart Chain, Arbitrum, Polygon e Solana; USDC su Base; ETH su Ethereum, Base e Arbitrum; SOL su Solana; Bitcoin. Il cliente paga con il wallet collegato, oppure con indirizzo e QR code da qualsiasi wallet o exchange, e può cambiare moneta e rete prima di pagare.

Configurazione: apri un account su https://blokbay.com e aggiungi il wallet; installa e attiva il plugin; inserisci chiave API segreta,
segreto del webhook ed eventualmente l'account (pk_live_...); nel pannello Blokbay imposta l'URL del webhook
`https://IL-TUO-NEGOZIO/api/_action/blokbay/webhook`; assegna il metodo di pagamento al canale di vendita. Ordini in USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD, AED.
