# COD Order Guard

A WooCommerce plugin built for Cash-on-Delivery (COD) stores — purpose-built for the Bangladesh e-commerce market, but usable anywhere COD is the primary payment method.

Built and maintained by [Omninode](https://omninode.tech).

## Features

- **Incomplete Order Recovery** — captures customer name, phone, email, address, and cart contents the moment a checkout form is filled, even if the order is never placed. Includes a searchable admin table, CSV export, click-to-call, and WhatsApp quick-contact links.
- **SteadFast Fraud Checker** — checks a customer's delivery success ratio against SteadFast Courier's history directly from the WooCommerce order screen, so you can spot high-risk COD orders before dispatch.
- **Meta Conversions API (Purchase tracking)** — sends a server-side `Purchase` event to Meta only once an order is delivery-confirmed (not just placed), with FBP/FBC capture, hashed customer data for Event Match Quality, SteadFast-ratio gating, admin-order exclusion, and a non-reversing `OrderCancelled` custom event for audience suppression. Fully HPOS-compatible.

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+ (HPOS supported)
- A [SteadFast Courier](https://steadfast.com.bd/) account with API access (for fraud checking)
- A Meta (Facebook) Pixel + Conversions API access token (for Purchase tracking)

## Installation

1. Download or clone this repository.
2. Zip the `cod-order-guard` folder, or upload it directly to `wp-content/plugins/`.
3. Activate **COD Order Guard** from the WordPress Plugins screen.
4. Go to **COD Order Guard → Settings** and add your SteadFast and Meta credentials.

## License

GPL-2.0-or-later, consistent with the WordPress plugin ecosystem.
