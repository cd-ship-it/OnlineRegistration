# Product Requirements Document: Vacation Bible School Registration System

## 1. Product overview

**Purpose**: Allow parents to register their children for Vacation Bible School (VBS) online, accept consent, and collect payment via Stripe. Provide an admin area to configure pricing, discounts, and view registrations.

**Users**:
- **Parents**: Complete registration on a single page with a 3-step flow (parent/emergency → kids → consent & payment), pay via Stripe, see confirmation after payment.
- **Admins**: Log in to set price per kid, early-bird and multi-kid discounts, and other settings; view and export registrations.

---

## 2. User roles

| Role   | Capabilities |
|--------|----------------|
| Parent | Submit registration on one page (parent + emergency contact, kids, consent with per-section checkboxes and digital signature); pay via Stripe; see confirmation. If payment is cancelled, return to form with data restored on step 3. |
| Admin  | Login; manage pricing and discounts; open/close registration; set consent form URL; view registrations list; export CSV. |

---

## 3. Functional requirements

### 3.1 Public registration

Registration is a **single page** (`/register`) with a **3-step wizard** (steps revealed via UI; one form, one submit at the end).

- **Step 1 – Parent / Guardian & Emergency contact**
  - **Parent fields**: First name, Last name, Email (required), Phone, Address, Home church (optional).
  - **Emergency contact**: One contact for the registration (name, phone, relationship to child(ren)). All optional.
  - "Next: Add kids" advances to step 2.

- **Step 2 – Add Kids**
  - Add one or more children dynamically. Per child: First name, Last name (required), Age, Gender (Boy/Girl), Date of birth, Last grade completed, Allergies / medical information. Minimum one child; maximum configurable by admin (default 10). Each block can be removed except the last.
  - "Back" returns to step 1; "Next: Consent & payment" advances to step 3.

- **Step 3 – Consent & Payment**
  - **Consent**: Content loaded from admin settings, split into sections. Each section is shown in its own card with a required checkbox: "I have read and agree to the terms above." The UI ensures all sections must be checked before proceeding; which sections were checked is not stored in the database.
  - **Digital signature**: One required text field at the end — parent types full legal name to sign. Digital signature and consent timestamp are stored on the registration record.
  - "Back" returns to step 2; "Go to payment" submits the full form and redirects to Stripe Checkout.

- **Payment**: After validation, user is redirected to Stripe Checkout. The collected parent email is sent to Stripe as `customer_email` so the checkout email field is pre-filled. Amount is computed server-side from settings (price per kid, early-bird discount, multi-kid discount). Cancel URL is `/register?cancelled=1`.

- **After payment cancelled**: When the user returns to `/register?cancelled=1`, form data (parent, emergency, kids, digital signature) is restored from session and the user is shown step 3 so they can edit and retry payment. Session data is cleared after successful payment (on success page).

- **Confirmation**: After successful payment, user is shown a thank-you page with registration details (parent name, email, total paid, list of children). Same outcome is enforced via Stripe webhook if the user does not return to the site.

**URLs**: `/add-kids` and `/consent` redirect to `/register` so all registration happens on the single page.

### 3.2 Admin

- **Login**: Username and password (default: admin / password). Session-based; logout clears session. Phase 2: Google OAuth (not in initial scope).
- **Pricing and discounts**:
  - Price per kid (stored in cents; displayed in admin as cents, e.g. 5000 = $50).
  - Early bird: start date, end date, and early-bird price per kid (cents). Applied when registration date falls within the range.
  - Multiple kids: minimum number of kids and price per kid (cents) for that tier (e.g. 2+ kids get a lower per-kid price).
- **Other settings**: Max kids per registration, consent form URL, registration open/closed toggle.
- **Registrations list**: Table of all registrations (parent name, email, number of kids, total, status, date). Filter by status (all, paid, draft). Export CSV.

### 3.3 URL design

- Public and admin URLs do not expose `.php` (e.g. `/register`, `/add-kids`, `/consent`, `/success`, `/cancel`, `/stripe-webhook`, `/admin`, `/admin/settings`, `/admin/registrations`, `/admin/logout`) via Apache `.htaccess` and `mod_rewrite`.

---

## 4. Non-functional requirements

- **Responsive**: Registration and admin UIs work on mobile and desktop (Tailwind CSS).
- **Security**: HTTPS in production; admin password stored hashed; input validated and output escaped; Stripe webhook signature verified.
- **Configuration**: All secrets and DB connection come from `.env`; no credentials in code.

---

## 5. Tech stack

- **Backend**: PHP 7.4+.
- **Database**: MySQL (tables: `registrations`, `registration_kids`, `settings`).
- **Front end**: HTML, JavaScript (vanilla), Tailwind CSS (CDN). Single-page registration uses step panels and client-side show/hide for "next page" feel.
- **Payment**: Stripe (Checkout Session); Stripe PHP SDK; customer email pre-filled from registration.
- **Server**: Apache with `mod_rewrite`; clean URLs via `.htaccess`.

---

## 6. Data model (summary)

- **registrations**: id, parent_first_name, parent_last_name, email, phone, address, home_church, emergency_contact_name, emergency_contact_phone, emergency_contact_relationship, consent_accepted, digital_signature, consent_agreed_at, stripe_session_id, status (draft | paid | cancelled), total_amount_cents, created_at, updated_at.
- **registration_kids**: id, registration_id (FK), first_name, last_name, age, gender, date_of_birth, last_grade_completed, medical_allergy_info, sort_order (plus legacy emergency contact columns if present).
- **settings**: key-value store for price_per_kid_cents, currency, early_bird_* (start_date, end_date, price_per_kid_cents), multi_kid_* (min_count, price_per_kid_cents), max_kids_per_registration, consent_form_url, registration_open, admin_password_hash.

---

## 7. Stripe integration

- **Checkout Session**: Created after full form validation (single POST with action=payment). `customer_email` is set from the registration email so the checkout form email field is pre-filled. success_url and cancel_url use clean URLs (e.g. `/success`, `/register?cancelled=1`). Metadata includes `registration_id`. Registration and consent data are saved to session before redirect so that if the user cancels, they return to step 3 with data restored.
- **Success handling**: On return to success URL, session is retrieved; registration is marked paid; `vbs_registration_data` is cleared from session; confirmation is shown. Webhook is the source of truth for payment.
- **Webhook**: Endpoint (e.g. `/stripe-webhook`) listens for `checkout.session.completed`; verifies signature with `STRIPE_WEBHOOK_SECRET`; marks corresponding registration as paid.

---

## 8. Out of scope / future

- Google OAuth for admin login.
- Editable consent form content or PDF upload in admin (consent text is in `consent.txt` with code fallback).
- Email confirmation to parent after payment (optional; code path exists for production).
- Rate limiting on registration and login.
