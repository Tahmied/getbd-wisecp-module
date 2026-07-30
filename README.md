# Get BD Registrar Module for WISECP

Production-oriented WISECP registrar integration for Get BD.

## Registration lifecycle

Get BD registration is asynchronous:

1. WISECP creates the registrar order and stores module-defined documents as verified.
2. The module reserves the domain through Get BD API v2.
3. The module uploads every required document through the legacy v1 API.
4. The module asks Get BD to process the order.
5. If documents or registry activation are pending, the module returns WISECP's
   pollable `FAIL` result. WISECP keeps the service in process and runs
   `sync()` through its `domain-activation-status` cron event.
6. `sync()` retries processing only for pending orders.
7. WISECP activates the domain only after Get BD reports the registry domain
   active.

Provider `PENDING`, `PROCESSING`, and inactive pre-registration states never
map to expired.

## API versions

- `POST /orders` and `POST /customers`: Get BD external API v2.
- Search, domain information, order processing, documents, renewal, domain
  listing, rates, and nameserver updates: Get BD external API v1.

The base URLs are configurable in `config.php`.

## Document approval

WISECP does not require manual admin approval for the document fields defined
by this registrar module.

The supplied Get BD OpenAPI contract does not expose an external document
approval endpoint. The module therefore:

- uploads the documents automatically;
- tries to process the order;
- remains awaiting while Get BD reports that approved documents are required;
- automatically resumes processing through WISECP cron after Get BD approves
  the documents or enables automatic approval for partner uploads.

The module never treats the provider's "APPROVED documents" error as a
successful registration.

## Supported capabilities

- Domain availability
- Asynchronous registration and activation synchronization
- Renewal with registry-expiry verification
- Hourly reconciliation for accepted asynchronous renewals
- Client-area nameserver management with read-after-write verification
- Domain information and registrar-domain import listing
- Admin link to the provider order

Hosted DNS record CRUD, transfers, EPP codes, privacy, transfer lock mutation,
and WHOIS mutation are intentionally not advertised because they are absent
from the supplied Get BD external API contract.

## WISECP client-area DNS tab

WISECP does not decide whether to show its DNS tab from the registrar's
`ModifyDns()` method. It reads the `dns_manage` capability from the matching TLD
product. The module now keeps that capability synchronized for `.bd` TLDs that
are already assigned to GetBD or referenced by an existing GetBD order:

- `dns_manage = 1` exposes the DNS tab and its nameserver form;
- `paperwork = 0` prevents WISECP's separate manual document-approval gate;
- `epp_code = 0` and `whois_privacy = 0` avoid advertising unsupported actions.

The synchronization runs when the module is loaded and from `HourlyCronJob`, so
it repairs existing GetBD TLD products as well as future orders. Registration
activation also writes `dns_manage` and the registry nameservers into the order
options.

Dynadot additionally shows a hosted DNS-record screen because Dynadot exposes
zone CRUD APIs. The supplied Get BD API has no equivalent endpoints, so GetBD's
DNS tab intentionally contains nameserver management only. Adding fake A, MX,
or TXT controls would allow WISECP to report a local success without changing
authoritative DNS.

## Cron requirement

WISECP cron must be running:

- `checking-order` runs every minute and activates pending registrations.
- `HourlyCronJob` reconciles renewals accepted before registry expiry changes
  become visible and repairs GetBD TLD client-area capability flags.

## Verification

Run the standalone suite without making live API requests:

```bash
php tests/GetBDTest.php
```

The suite covers v1/v2 routing, log redaction, rate limiting, document
preservation, pending registration, idempotent activation, client-area
capabilities, status mapping, renewal verification, asynchronous renewal state,
nameserver verification, and local validation.

Before production rollout, test one controlled registration in the Get BD
sandbox or a provider-approved test account. Get BD should also confirm the
document requirements in `config.php` for each supported `.bd` category.
