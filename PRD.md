# Product Requirements Document: Vacation Bible School Registration System

## 1. Product overview

**Purpose**: Allow parents to register their children for Vacation Bible School (VBS) online, accept consent, and collect payment via Stripe. Provide an admin area to configure pricing, discounts, and view registrations.

**Users**:
- **Parents**: Complete registration form (parent info + one or more kids), accept consent, pay via Stripe.
- **Admins**: Log in to set price per kid, early-bird and multi-kid discounts, and other settings; view and export registrations.

---

## 2. User roles

| Role   | Capabilities |
|--------|----------------|
| Parent | Submit registration (parent + kids), consent, pay; see confirmation after payment. |
| Admin  | Login; manage pricing and discounts; open/close registration; set consent form URL; view registrations list; export CSV. |

---

## 3. Functional requirements

### 3.1 Public registration

- **Parent fields** (required except phone): First name, Last name, Email, Phone (optional).
- **Kids**: Add one or more children dynamically. Per child: First name, Last name, Age (optional), Allergies / medical information (optional). Minimum one child; maximum configurable by admin (default 10). Each block can be removed except the last.
- **Consent**: Required checkbox: “I agree to the consent form.” Link points to a configurable URL (admin setting).
- **Payment**: After validation, user is redirected to Stripe Checkout. Amount is computed server-side from settings (price per kid, early-bird discount, multi-kid discount).
- **Confirmation**: After successful payment, user is shown a thank-you page with registration details (parent name, email, total paid, list of children). Same outcome is enforced via Stripe webhook if the user does not return to the site.

### 3.2 Admin

- **Login**: Username and password (default: admin / password). Session-based; logout clears session. Phase 2: Google OAuth (not in initial scope).
- **Pricing and discounts**:
  - Price per kid (stored in cents; displayed in admin as cents, e.g. 5000 = $50).
  - Early bird: start date, end date, discount percent. Applied when registration date falls within the range.
  - Multiple kids: minimum number of kids and discount percent (e.g. 2+ kids get 10% off).
- **Other settings**: Max kids per registration, consent form URL, registration open/closed toggle.
- **Registrations list**: Table of all registrations (parent name, email, number of kids, total, status, date). Filter by status (all, paid, draft). Export CSV.

### 3.3 URL design

- Public and admin URLs do not expose `.php` (e.g. `/register`, `/admin/settings`) via Apache `.htaccess` and `mod_rewrite`, to hide technology from end users.

---

## 4. Non-functional requirements

- **Responsive**: Registration and admin UIs work on mobile and desktop (Tailwind CSS).
- **Security**: HTTPS in production; admin password stored hashed; input validated and output escaped; Stripe webhook signature verified.
- **Configuration**: All secrets and DB connection come from `.env`; no credentials in code.

---

## 5. Tech stack

- **Backend**: PHP 7.4+.
- **Database**: MySQL (tables: `registrations`, `registration_kids`, `settings`).
- **Front end**: HTML, JavaScript (vanilla), Tailwind CSS (CDN).
- **Payment**: Stripe (Checkout Session); Stripe PHP SDK.
- **Server**: Apache with `mod_rewrite`; clean URLs via `.htaccess`.

---

## 6. Data model (summary)

- **registrations**: id, parent_first_name, parent_last_name, email, phone, consent_accepted, stripe_session_id, status (draft | paid | cancelled), total_amount_cents, created_at, updated_at.
- **registration_kids**: id, registration_id (FK), first_name, last_name, age, medical_allergy_info, sort_order.
- **settings**: key-value store for price_per_kid_cents, currency, early_bird_*, multi_kid_*, max_kids_per_registration, consent_form_url, registration_open, admin_password_hash.

---

## 7. Stripe integration

- **Checkout Session**: Created after form validation; success_url and cancel_url use clean URLs (e.g. `/success`, `/register?cancelled=1`). Metadata includes `registration_id`.
- **Success handling**: On return to success URL, session is retrieved; registration is marked paid and confirmation is shown. Webhook is the source of truth for payment.
- **Webhook**: Endpoint (e.g. `/stripe-webhook`) listens for `checkout.session.completed`; verifies signature with `STRIPE_WEBHOOK_SECRET`; marks corresponding registration as paid.

---

## 8. Out of scope / future

- Google OAuth for admin login.
- Editable consent form content or PDF upload in admin.
- Email confirmation to parent after payment.
- Rate limiting on registration and login.
