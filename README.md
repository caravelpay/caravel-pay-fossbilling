# Caravel Pay for FOSSBilling

- Docs: <https://caravelpay.app/docs/integrations#fossbilling>
- Download and checksums: <https://caravelpay.app/plugins/#fossbilling>
- Status: beta. It passes our tests against a stand-in of the platform and has not yet run on a production install. Tell us if anything looks off.
- Questions: [hello@caravelpay.app](mailto:hello@caravelpay.app)

A payment adapter for **FOSSBilling 0.7.2+** (it runs on the PHP version your FOSSBilling requires). Clients pay invoices by card, Apple Pay, Google Pay or USDC on the hosted checkout. Each payment goes to your own Polygon wallet, minus the 2% Caravel fee, in the token the buyer paid with (card payments arrive as USDC). The Caravel Pay PHP SDK is bundled; nothing else to install.

## Install

1. Download `caravel-pay-fossbilling.zip` and unzip it in your FOSSBilling root. This adds the folder `library/Payment/Adapter/CaravelPay/`.
2. In the admin area, open **Payment gateways** (System menu), choose the **New payment gateway** tab and install **CaravelPay**.
3. Open it, paste your **Payout wallet (Polygon)** and an optional contact email, tick **Enabled** and save.

The adapter connects to Caravel Pay (`POST /v1/connect`) the first time a client pays, using your FOSSBilling URL and wallet. The merchant id and secret are stored encrypted (with FOSSBilling's own encryption) in `caravelpay_*` tables.

### Requirements

- Invoices in **US dollars** are charged as they are. Invoices in EUR, GBP, CAD, AUD, SEK, NOK, DKK, CHF, PLN, CZK, JPY and other supported currencies are converted to US dollars when the client pays; the client is told before paying. For any other currency the adapter shows a message instead of a payment button.
- FOSSBilling served over **HTTPS**, with `ipn.php` reachable from the internet by `POST`.

## How invoices update

When the client chooses Caravel Pay on an invoice, the adapter creates the checkout (or reuses the open one) and redirects the client to it. Webhooks arrive at FOSSBilling's `ipn.php?gateway_id=…&invoice_id=…`. The adapter verifies them, **re-fetches the checkout** from the API, and then:

| Checkout | FOSSBilling |
|---|---|
| `paid` | The amount is added to the client's balance and the invoice is paid with credits, the way FOSSBilling's own gateways do it. The transaction gets the payout tx hash as its id and becomes *processed*, and a note gives the payout |
| `underpaid` / `review` | Not paid. A note is added to the invoice and the event is logged |
| `expired` | Logged. The client can pay again for a new checkout |

FOSSBilling's `ipn.php` normally answers `200` even when an adapter fails. This adapter sets the HTTP status itself (401 for a bad signature, 503 for a temporary problem) so Caravel Pay retries failed deliveries for up to 24 hours. Every webhook is visible as a transaction under **Invoices › Transactions**.

There is no test mode: test with a small real invoice. Recurring invoices are paid one by one; there are no automatic charges.

## Switching from PayGate

PayGate's FOSSBilling adapter can stay installed while you switch. Install Caravel Pay, paste the same Polygon wallet you used with PayGate, and disable PayGate once its open invoices are settled. A short payment shows as `underpaid` and is never applied, so there is no tolerance setting to carry over.

## Notes

- **Changing your wallet:** edit the gateway. The next payment gets new keys. Checkouts already started finish on the old wallet, and their webhooks still verify for 7 days.
- **Who pays the fees:** the gateway's **Who pays the fees** setting. *Seller pays everything* (default): your client pays the invoice total and both fees come off what you receive. *Split: buyer pays the card fee*: the card provider adds its fee to your client's total and you pay the 2% Caravel fee. *Buyer pays everything*: the 2% Caravel fee is added to your client's total as well, so you receive the full invoice total. Clients see a line about it before they pay. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules first.
- **Staging:** define `CARAVEL_PAY_API_BASE` in `config.php` to use another API host.

## Download

- [Latest release](https://github.com/caravelpay/caravel-pay-fossbilling/releases/latest): `caravel-pay-fossbilling.zip`, with `SHA256SUMS`.
- The same file is at <https://caravelpay.app/plugins/#fossbilling>, and its checksum is in <https://caravelpay.app/download/SHA256SUMS>.

## Docs and support

- Guide: <https://caravelpay.app/docs/integrations#fossbilling>
- API reference: <https://caravelpay.app/docs/>
- Questions and bug reports: [hello@caravelpay.app](mailto:hello@caravelpay.app), or open an issue here.

## Licence

MIT. See `LICENSE`.
