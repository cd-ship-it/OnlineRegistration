# Product Requirements Document: Vacation Bible School Registration System

## 1. Product overview

**Purpose**: Allow parents to register their children for Vacation Bible School (VBS) online, accept consent, and collect payment via Stripe. Provide an admin area to configure pricing, discounts, event description, consent content, and view registrations.

**Users**:
- **Parents**: Complete registration on a single page with a 3-step flow (parent/emergency → kids → consent & payment), pay via Stripe, see confirmation after payment. Event description and date are shown from admin settings. If payment is cancelled, return to form with data restored on step 3; resubmitting updates the same draft registration (no duplicates).
- **Admins**: Log in to set event description, pricing and discounts, consent content, open/close registration; view registrations list (sortable, with photo consent); view individual registration details; export CSV.

---

## 2. User roles

| Role   | Capabilities |
|--------|----------------|
| Parent | Submit registration (parent + emergency + alternative pickup, kids with grade and t-shirt size, consent with per-section checkboxes and Photo YES/NO, digital signature); pay via Stripe; see confirmation. If payment is cancelled, return to step 3 with data restored; resubmit updates the same draft (no duplicate). |
| Admin  | Login; set event description, pricing and discounts, consent content; open/close registration; view sortable registrations list (with photo consent); view individual registration; export CSV. |

---

## 3. Functional requirements

### 3.1 Public registration

Registration is a **single page** (`/register`) with a **3-step wizard** (steps revealed via UI; one form, one submit at the end).

- **Above the form**: Price boxes—when before early-bird end date, two columns (early-bird price in yellow, regular price in white); after early-bird end date, single white box with regular price only. Event description (date, age, etc.) is loaded from admin "Event Description" setting and displayed in a card above the form.

- **Step 1 – Parent / Guardian & Emergency contact**
  - **Parent fields**: First name, Last name, Email (required), Phone, Address, Home church (optional). Optional: Alternative pick up name, Alternative pick up phone.
  - **Emergency contact**: One contact for the registration (name, phone, relationship to child(ren)). All optional.
  - "Next: Add kids" advances to step 2.

- **Step 2 – Add Kids**
  - Add one or more children dynamically. Per child: First name, Last name (required), Age, Gender (Boy/Girl), Date of birth, Last grade completed (dropdown: Preschool, Pre K, K, 1st–5th), T-shirt size (dropdown: Youth XS/S/M/L/XL), Allergies / medical information. Minimum one child; maximum configurable by admin (default 10). Each block can be removed except the last.
  - "Back" returns to step 1; "Next: Consent & payment" advances to step 3.

- **Step 3 – Consent & Payment**
  - **Consent**: Content loaded from admin "Consent content" setting; each paragraph (separated by blank lines) is one section with a required checkbox. Section 5 (Photo & Video Release) is always shown at the end with YES/NO options; value stored in `registrations.photo_consent`. Which sections were checked is not stored in the database.
  - **Digital signature**: One required text field at the end — parent types full legal name to sign. Digital signature and consent timestamp are stored on the registration record.
  - "Back" returns to step 2; "Go to payment" submits the full form and redirects to Stripe Checkout.

- **Payment**: After validation, user is redirected to Stripe Checkout. The collected parent email is sent to Stripe as `customer_email` so the checkout email field is pre-filled. Amount is computed server-side from settings (price per kid, early-bird discount, multi-kid discount). Cancel URL is `/register?cancelled=1`.

- **After payment cancelled**: When the user returns to `/register?cancelled=1`, form data (parent, emergency, kids, digital signature) and the existing draft `registration_id` are restored from session; user is shown step 3. On resubmit, the same draft registration is updated (no duplicate record). Session data is cleared after successful payment (on success page).

- **Confirmation**: After successful payment, user is shown a thank-you page with registration details (parent name, email, total paid, list of children). Same outcome is enforced via Stripe webhook if the user does not return to the site.

**URLs**: `/add-kids` and `/consent` redirect to `/register` so all registration happens on the single page.

### 3.2 Admin

- **Login**: Username and password (default: admin / password). Session-based; logout clears session. Phase 2: Google OAuth (not in initial scope).
- **Event Description**: Textarea at top of settings. Content (date, time, age requirements, etc.) is shown on the registration page in place of hardcoded event text. Lines starting with "DATE:" or "AGE:" are styled as bold.
- **Pricing and discounts**:
  - Price per kid (stored in cents; displayed in admin as dollars).
  - Early bird: start date, end date, and early-bird price per kid (cents). Applied when registration date falls within the range.
  - Multiple kids: minimum number of kids and price per kid (cents) for that tier.
- **Consent content**: Textarea; each paragraph is one consent section. Photo & Video Release (Section 5) is always appended and not editable here.
- **Other settings**: Max kids per registration, registration open/closed toggle.
- **Registrations list**: Table columns: Parent (link to view), Email, Kids, Photo consent (green/red for Yes/No), Status, Registered Date (12-hour AM/PM). Sortable by each column. Filter by status (all, paid, draft). Export CSV (includes Photo consent; no Total column).
- **Registration view**: Dedicated page per registration (two-column layout): Parent/Guardian (including alternative pickup), Emergency contact, Consent & payment, and Children (with grade, t-shirt size, medical). Photo consent shown in a standalone highlighted box (green for Yes, red for No).

### 3.3 URL design

- Public and admin URLs do not expose `.php` (e.g. `/register`, `/add-kids`, `/consent`, `/success`, `/cancel`, `/stripe-webhook`, `/admin`, `/admin/settings`, `/admin/registrations`, `/admin/registrations/view`, `/admin/logout`) via Apache `.htaccess` and `mod_rewrite`.

---

## 4. Non-functional requirements

- **Responsive**: Registration and admin UIs work on mobile and desktop (Tailwind CSS).
- **Security**: HTTPS in production; admin password stored hashed; input validated and output escaped; Stripe webhook signature verified.
- **Configuration**: All secrets and DB connection come from `.env`; no credentials in code.
- **Timestamps**: All timestamps (created_at, updated_at, consent_agreed_at) are in Pacific Time (Los Angeles). PHP default timezone is set in config; timestamps are generated in PHP and stored in the database; the database timezone is not changed.
- **Footer**: Every page (register, success, admin) shows a footer with copyright (current year), Crosspoint Church link, and "Our Privacy Promise" link to `/privacy_summary.html`.

---

## 5. Tech stack

- **Backend**: PHP 7.4+.
- **Database**: MySQL (tables: `registrations`, `registration_kids`, `settings`).
- **Front end**: HTML, JavaScript (vanilla), Tailwind CSS (CDN). Single-page registration uses step panels and client-side show/hide for "next page" feel.
- **Payment**: Stripe (Checkout Session); Stripe PHP SDK; customer email pre-filled from registration.
- **Server**: Apache with `mod_rewrite`; clean URLs via `.htaccess`.

---

## 6. Data model (summary)

- **registrations**: id, parent_first_name, parent_last_name, email, phone, address, home_church, alternative_pickup_name, alternative_pickup_phone, emergency_contact_* (name, phone, relationship), consent_accepted, digital_signature, consent_agreed_at, photo_consent (yes/no), stripe_session_id, status (draft | paid), total_amount_cents, created_at, updated_at.
- **registration_kids**: id, registration_id (FK), first_name, last_name, age, gender, date_of_birth, last_grade_completed, t_shirt_size, medical_allergy_info, sort_order (plus legacy emergency columns if present). No per-kid emergency in current UI.
- **settings**: key-value store for event_description, price_per_kid_cents, currency, early_bird_* (start_date, end_date, price_per_kid_cents), multi_kid_* (min_count, price_per_kid_cents), max_kids_per_registration, consent_content, registration_open, admin_password_hash. No consent_form_url or consent_items table; consent is free-text from consent_content.

---

## 7. Stripe integration

- **Checkout Session**: Created after full form validation (single POST with action=payment). If the user previously cancelled, the existing draft registration is updated instead of creating a new one (session stores `registration_id`). `customer_email` is set from the registration email. success_url and cancel_url use clean URLs. Metadata includes `registration_id`. Form data and `registration_id` are saved to session before redirect so that on cancel, the user returns to step 3 with data restored and can retry without creating a duplicate.
- **Success handling**: On return to success URL, session is retrieved; registration is marked paid and `updated_at` set (Pacific time); `vbs_registration_data` is cleared; confirmation email is sent to the parent when `APP_ENV=production`; confirmation page is shown. Webhook can also mark registration paid if user does not return.
- **Webhook**: Endpoint listens for `checkout.session.completed`; verifies signature with `STRIPE_WEBHOOK_SECRET`; marks corresponding registration as paid and sets `updated_at`.

---

## 8. Out of scope / future

- Google OAuth for admin login.
- Email confirmation to parent after payment is implemented for production (`APP_ENV=production`); uses PHP `mail()` from success page.
- Rate limiting on registration and login.

## 9. Implemented details (reference)

- **Privacy**: `/privacy_summary.html` — summary cards plus full VBS Privacy Notice (from PDF). Linked in footer as "Our Privacy Promise."
- **Registration UX**: No auto-scroll to form on initial page load; scroll only when user changes steps (Next/Back).
- **Draft reuse**: On payment cancel, session holds `registration_id`; resubmit updates that draft (UPDATE + replace kids) so no duplicate registrations.
- **Consent**: No `registration_consent_items` or `consent.txt`; consent text is entirely from settings `consent_content`.
