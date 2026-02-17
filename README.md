# VBS Registration System

Vacation Bible School registration with parent/kids form, consent, and Stripe payment. Admin area for pricing and discounts.

## Requirements

- PHP 7.4+
- MySQL
- Apache with `mod_rewrite` (for clean URLs)
- Composer

## Setup

1. Copy `.env.example` to `.env` and set your database and Stripe keys.
2. Run `composer install`.
3. Create the database and run the schema:
   ```bash
   mysql -h DB_HOST -P DB_PORT -u DB_USER -p DB_NAME < schema.sql
   ```
4. **RewriteBase**: In `.htaccess`, set `RewriteBase` to your app path. If the app is at `http://localhost/OnlineRegistration`, keep `RewriteBase /OnlineRegistration`. If the app is at document root, use `RewriteBase /`.
5. Default admin login: **admin** / **password**. Change via `ADMIN_PASSWORD_HASH` in `.env` (generate with `php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"`) or update the `admin_password_hash` value in the `settings` table.

## Stripe webhook (optional but recommended)

In Stripe Dashboard → Webhooks → Add endpoint:

- URL: `https://your-domain.com/OnlineRegistration/stripe-webhook` (or your path)
- Events: `checkout.session.completed`

Add the signing secret to `.env` as `StripeWebhookSecret` or `STRIPE_WEBHOOK_SECRET`.

## URLs (clean, no .php)

- Public: `/`, `/register`, `/success`, `/cancel`
- Admin: `/admin`, `/admin/settings`, `/admin/registrations`, `/admin/logout`

See [PRD.md](PRD.md) for full product and technical requirements.
