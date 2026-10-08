# Evangelou App — Project Memory

## Client brief update — 2026-10-03

The Evangelou app will have two main areas:

1. **Customer ordering app**
   - Customers can place orders through the app, similar to the Arman app flow.
   - The app will include ordering, cart, checkout, order status, and customer-facing ordering UX.
   - The ordering app may use its own application backend/database (e.g. Supabase) while integrating with the existing WordPress/WooCommerce setup where appropriate.

2. **Membership / Club / Loyalty workflow**
   - Evangelou already has a list of paying subscribers/members.
   - Subscription price: **€20/month**.
   - Active subscribers receive **one free coffee per day**.
   - Evangelou also has a separate list of users subscribed only to the newsletter.
   - The newsletter list is connected through WordPress to an **Amazon-based service**. The exact Amazon/AWS service still needs to be confirmed before implementation.

## Newsletter / Amazon tag logic

The Amazon-connected mailing/list system should distinguish users by tag/status:

- Paying active subscriber: tag such as **active**.
- Newsletter-only subscriber: a different tag/status indicating newsletter registration without an active paid membership.
- When membership status changes, the integration should keep the mailing/tag state synchronized.
- Exact tag names and the exact Amazon/AWS product/API will be finalized after reviewing the current WordPress integration.

## In-store tablet acquisition flow

Staff will have a tablet at the till displaying a public QR code.

A customer scans that QR code and follows a short onboarding flow:

### Step 1 — Newsletter signup
- Customer enters the required contact details.
- Customer is registered to the existing newsletter system.
- The appropriate newsletter-only tag/status is applied.

### Step 2 — Membership subscription
- Customer is invited to become a paid subscriber.
- Subscription is purchased through the existing **WooCommerce** product/subscription flow.
- Once the paid membership is active, the customer's Amazon-connected mailing/list tag/status changes to the active-member tag.

## Unique customer QR code

Every registered customer/member will have a **unique QR code**.

At the till, staff scan the customer's QR code to immediately see:

- Customer identity / profile.
- Whether the customer is an active paying subscriber.
- Whether the subscription is inactive/expired.
- Whether the customer has already redeemed today's free coffee.
- Relevant membership information needed by staff.

The QR should identify the customer using a safe opaque identifier/token rather than exposing sensitive personal data directly in the QR payload.

## Daily free coffee benefit

Active members receive **one free coffee per calendar day**.

When staff serve the free coffee:

- They scan the customer's unique QR code.
- The system checks membership status.
- The system checks whether today's coffee has already been redeemed.
- If eligible, staff record the redemption.
- A second redemption on the same day must be prevented.
- The system stores date/time and relevant staff/action metadata for the redemption.

## Coffee preference / consumption history

Staff should be able to quickly record which coffee the customer took.

The customer profile should keep a simple coffee history, for example:

- Coffee type.
- Date/time.
- Whether it was the free daily member coffee or another recorded preference/order when applicable.

This history is intended to help the business understand each customer's usual coffee preferences and past selections.

## WordPress integration approach

A custom WordPress integration plugin will act as the bridge between the app and the client's existing WordPress systems.

Where possible, the plugin should use supported PHP APIs/functions/classes/hooks from the existing WordPress plugins instead of coupling the app directly to their internal database tables.

The custom plugin/API should be responsible for:

- Reading customer/member data from the existing systems.
- Checking active membership/subscription status.
- Coordinating newsletter/Amazon tag synchronization.
- Exposing only the app-specific REST endpoints required by the customer/staff apps.
- Handling or coordinating QR/member validation and coffee redemption logic.
- Avoiding direct frontend access to WordPress/MySQL internals.

## Existing systems / dependencies to confirm

Before final production implementation, confirm:

- Exact plugin/product used for paid membership/subscriptions.
- Exact plugin/system used for newsletter contacts.
- Exact Amazon/AWS service connected to WordPress.
- How newsletter tags/segments are currently represented.
- Whether WooCommerce Subscriptions or another subscription mechanism is responsible for recurring €20/month payments.
- Existing available hooks/APIs/webhooks for membership activation, cancellation, renewal, failed payment, and newsletter synchronization.

## Product principle

Do not duplicate existing WordPress/CRM/newsletter data without a clear need. Keep each system the source of truth for the data it already owns, and use the custom integration layer to synchronize only the statuses and operational data the app requires.

## Contract and implementation scope — October 2026

- The client accepted **Phase 1 Evangelou Club only**, for **€1,500 + VAT**. Online customer ordering, menu, cart, delivery and ordering admin are explicitly **out of scope** and may be a later Phase 2.
- The intended Club launch target is **early December 2026**, subject to integration discoveries and timely access/approvals.
- The first year of technical support was offered at no extra charge; from year 2 the proposed support is **€300 + VAT/year**. Hosting/backend/provider costs, if later needed, are separate and must be approved by the client.
- Site technician offered WordPress **Administrator** credentials and **SFTP** username/password, but **not Plesk** access because it hosts sensitive content. Technician can create a database if needed. Prefer to assess custom tables in the existing WordPress database first; no separate database is assumed necessary yet.
- All initial production-site auditing must be **read-only**. No updates, installs, data edits, credentials sharing, or production migrations before a backup/staging and approval.

## Verified WordPress admin plugin inventory — screenshot 2026-10-08

The WordPress Installed Plugins screenshot shows **12 total plugins**. Among them:

- **FluentCart 1.7.0 — active.** This is the observed e-commerce plugin.
- **FluentCRM – Marketing Automation for WordPress 3.2.5 — active.** Candidate system for newsletter contacts, tags and segments.
- **Fluent Forms 6.2.15 — active.** Candidate for signup forms.
- Also active: Elementor, DFD Theme Extensions, Really Simple Security, Slider Revolution, WordPress Importer, WPBakery Page Builder.
- WooCommerce and Paid Memberships Pro **do not appear in the complete 12-plugin list**; prior references in the earlier brief to WooCommerce and PMPro were assumptions and **must not be treated as confirmed installed infrastructure**.
- The exact paid subscription/rebilling/membership mechanism **has not yet been confirmed**. Inspect FluentCart products, subscription capability, payment gateway/settings, custom code and licenses before selecting the integration.
- The exact Amazon/AWS service **has not yet been confirmed**. Amazon may only be the email-delivery provider while contact tags remain in FluentCRM; inspect FluentCRM email and contact/tag settings before deciding integration logic.
- Next read-only audit targets: FluentCart product/catalog and subscription/payment settings; FluentCRM tags and email provider (mask secrets); Fluent Forms registration forms. Avoid showing real customer personal data.

## Read-only WordPress audit — 2026-10-08 (second screenshot set)

- Full Installed Plugins view confirms 12 visible standard plugins, 9 active, 3 inactive. Inactive: Carousel Slider, Hello Dolly, LayerSlider WP. No WooCommerce or PMPro appears in the visible installed plugins list. This does not rule out hidden plugins, MU-plugins, multisite network plugins, or an external platform.
- FluentCart 1.7.0 dashboard has initial setup banner **"Set up your pages first to get started"**, and a Getting Started checklist showing Setup Pages, Add Details to Store, Add Your First Product, Setup Payment Methods, Install Elementor Addon all unchecked.
- Dashboard shows **Total Products: 0**, **Orders: 0** (Last 30 Days header), **Revenue: $0**, and zero recent activities. This strongly suggests FluentCart has not been configured as the active subscription checkout, but requires checking Products, Subscriptions, and Settings; do not assert final subscription setup without checking.
- FluentCart has a **Subscriptions** navigation tab. Presence of tab does NOT confirm recurring payments are enabled, configured or licensed.
- WordPress top bar previously showed **Test Mode**, but source/status still need confirmation.
- Priority audit next: FluentCart → Subscriptions (prefer aggregate/empty state without personal info), Products, Settings → payment setup; FluentCRM Tags and email sending integration with any secrets masked.
- Do not configure live payment integrations, run test transactions, install/update plugins, or access/disclose real customer records during read-only audit.

## Read-only WordPress audit — FluentCRM and FluentCart, 2026-10-08

- User verified **FluentCart Products and Subscriptions are empty** in their respective admin screens. Do not assume the paid €20/month subscription currently exists or that recurring billing is configured; inspect FluentCart settings/features/licensing.
- FluentCRM dashboard screenshot shows **Active Contacts: 178**, **Campaigns: 0**, **Emails Sent: 0**, and **Active Automations: 0**. Getting Started indicators mark **Create a Tag**, **Import Contacts**, and **Create a Form** completed (3 of 5). The screenshot shows recent contacts with 'Subscribed' badges, but marketing consent for all contacts remains unverified. No names or emails should be copied into project documentation.
- Dashboard has an **SMTP** navigation entry and a promotional **Set Up FluentSMTP** panel; neither proves which email provider or AWS service is configured.
- Follow-up audit: screenshot FluentCRM → Contacts → Tags (without exposing customer records); FluentCRM → Settings/email sending or SMTP configuration showing provider but redacting secrets; FluentCart → Settings → Payment Methods to establish test/live mode and recurring billing readiness. Never modify production settings until staging/backup and explicit approval.

## Read-only WordPress audit — FluentCRM tags/email settings, 2026-10-08

- FluentCRM → Contacts → Tags screenshot: **exactly one tag**, ID **1**, title **"Ευαγγέλου Club"**, with **178** "Subscribed" contacts. This is a CRM/newsletter contact status, NOT proof of 178 active paid memberships. Do not bulk change this existing tag or infer member eligibility from it.
- FluentCRM → Settings → Email Service screenshot displays FluentSMTP **installation promotional panel**, so it does not prove FluentSMTP is installed/active. Under **Bounce Handling Settings**, email service provider dropdown currently shows **Amazon SES** and an **Amazon SES Bounce Handler URL**. Treat this as a strong SES-related configuration clue, but **not verified active Amazon SES sending**; verify actual email-sending plugin/provider and SMTP configuration.
- Screenshot exposes a URL with a verification-key-like token; avoid reproducing URL/tokens in screenshots/reports and assess whether any credential needs rotation.
- FluentCRM → Settings → General Settings screenshot shows unchecked: auto-sync WordPress user data with FluentCRM contact data; create new FluentCRM contacts on WP user registration; FluentCart checkout newsletter subscription checkbox. No changes made.
- User could not find payment settings because screenshots showed **FluentCRM Settings**, not **FluentCart Settings**. To inspect checkout payments, navigate **FluentCart → Settings → Payment Settings** (according to official FluentCart docs, product/version menu may vary). Inspect only, no gateway activation. FluentCart admin top bar showed Test Mode in screenshots, exact status source to confirm.
- Important product decision: implement subscription status from actual billing platform, not CRM subscription/tag status. Plan separate membership lifecycle tags only after existing contacts and consent meaning are established. Newsletter signup must use appropriate marketing consent.

## Read-only audit — FluentCart Payment Settings, 2026-10-08

- Screenshot from FluentCart → Settings → Payment Settings shows **Stripe = Disabled**, **PayPal = Disabled**, **Cash on Delivery = Disabled**.
- Other gateways listed (e.g. Paystack, Razorpay, Mercado Pago, Flutterwave, SSLCommerz; Pro-badged Paddle, Mollie, Authorize.Net, Square), but the screenshot does not prove any of them are installed, configured, or enabled.
- FluentCart products and subscriptions remain empty by user's earlier inspection. No working recurring billing has yet been demonstrated, and WordPress admin bar previously displayed Test Mode.
- Official FluentCart feature comparison says **subscription products are supported in the Free version** (fluentcart.com/free-vs-pro/); official docs describe monthly subscription products, Stripe setup, and Gateway Billing vs Store Billing. However, do not assume any particular capability or Pro-dependent option is configured for this site.
- Decide with client whether **automatic monthly recurring card charges** are expected or whether customers should **manually renew via payment link**; this choice affects gateway/configuration and membership status logic.
- Next read-only inspections: FluentCart → Settings → Store Settings (subscriptions/billing mode if present); FluentCart → Settings → Features & addon if needed; do not enable Stripe, insert credentials, switch Test Mode, or create paid products without staging/approval.

## Read-only WordPress audit — FluentCart Store Setup and Subscription Settings, 2026-10-08

- User showed FluentCart → Settings → Store Settings → Subscriptions.
- **Renewal Billing = Gateway Billing**: screen states Stripe, PayPal, and other subscription-ready gateways automatically charge renewals. This is the **selected mode**, not proof of a configured gateway; earlier audit showed Stripe and PayPal both Disabled.
- **Staging Protection = checked**, wording: do not bill live subscriptions from site while in test mode. This is appropriate for auditing, should not be switched without approval.
- **Early Payment** marked as FluentCart Pro feature, grayed out; not in agreed core Club requirement.
- FluentCart → Settings → Store Settings → Store Setup screenshot shows **Store Name blank**, **Store Mode = Test**, **Store Address/Business Details blank**, **Checkout Currency = United States Dollar**, **Timezone = Browser**, while Date & Time Format = WordPress. Strong evidence that FluentCart is not production-configured yet.
- Important before go-live: set EUR, store/merchant identity and billing details, configure recurring-compatible gateway, verify webhooks/renewal/failure handling, and ensure every coffee redemption day boundary is server-defined in Europe/Athens timezone, NOT client/browser timezone.
- **No settings changed.** Keep test mode and staging protection during audit. Before creating subscription product, confirm desired automatic recurring billing with client, whether merchant already has Stripe/PayPal, whether FluentCart checkout can be fully configured and tested in staging, and applicable pricing/licenses/tax/legal checkout details. Use actual paid subscription billing state as source of truth, not newsletter tag.
