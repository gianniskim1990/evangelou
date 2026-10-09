# Evangelou App — Project Memory

## STANDING WORKFLOW — Claude Code collaboration (explicit user instruction, 2026-10-09)

**Priority:** This section is the default development protocol for all subsequent Evangelou Club tasks, modeled on the user's cAIrelink collaboration. It supersedes the earlier ad-hoc practice of ChatGPT directly editing production-feature code. Do not silently abandon it in future chats.

1. **ChatGPT is the architect/reviewer/prompt writer; Claude Code is the implementation agent.** For every new development task, first inspect the current source / branch / PR / `memory.md` and relevant previous results; identify the smallest safe, approved scope. Unless the user explicitly asks ChatGPT to implement directly, **provide a ready-to-paste Claude Code prompt instead of silently writing application code**.
2. **Before EVERY Claude Code prompt, explicitly recommend which available Claude model to use and WHY.** Prefer **Opus** for complex architecture, security-critical logic, authentication, billing/membership lifecycle, database concurrency, migrations, integrations, root-cause investigation and full audits. Prefer **Sonnet** for well-specified localized edits, small UI changes, documentation and narrow mechanical tasks after an accepted design. Re-evaluate model choice per task rather than always choosing the same model.
3. **Prompts must be complete, copy/paste ready, in one normal fenced `text` code block** (not a WritingBlock or scattered snippets). Include precise goal, repository location, context, locked scope, files/components to audit, safety constraints, changes requested, tests, failure/rollback conditions, and required final report. Explicitly instruct Claude Code to inspect the repo and `memory.md` FIRST and never guess current state from stale summaries.
4. **Work sequentially in small, auditable tasks.** For risky tasks, begin with read-only source/architecture/security audit and acceptance criteria before implementation. When significant ambiguities occur, stop and ask for evidence/decision; never invent features or expand scope. No ordering app work in the agreed Club-only Phase 1.
5. **Git discipline:** verify freshest `main`, protect unrelated changes, use a scoped feature branch / draft PR, avoid opportunistic refactors, keep commits and changes traceable. Report exact branch, commit SHA, PR URL/status and any uncommitted files. Do not merge, deploy, turn on payments, connect live CRM, or install a migration automatically; require explicit user approval, confirmed tests and staging gates.
6. **Testing and review like cAIrelink:** insist on relevant unit, integration, negative, concurrency and regression tests, compare against known baseline, and use meaningful mutation/fault-injection tests for high-risk business logic. Test double redemption across two tills, idempotency retries, Athens midnight/DST, expired/unpaid/cancelled PMPro entitlements, QR forgery, authentication/authorization, CRM consent and sync. Run lint/typecheck/build/PHP lint/SQL integration in suitable real environments as applicable; distinguish tests actually run from merely planned. Never claim passing checks without evidence.
7. **Evidence-first reporting:** Claude Code must finish each run with a concise structured report: what it inspected, actual files changed, reasoning/risks, exact tests and pass/fail counts, CI links/results, SQL/schema/migration status, branch/commit/PR, blockers, security issues, and **the exact next action for the user**. ChatGPT then reviews the pasted report critically, verifies via GitHub/tools when possible, and provides the next prompt or a go/no-go decision. Do not automatically accept Claude's success claim.
8. **Memory-first task transitions:** BEFORE switching from a completed Club task/subtask to the next, update this `memory.md` with the completed task's VERIFIED status (implemented/merged/deployed/live-tested are distinct), next active task and important safety constraints. Keep an explicit current-task pointer, outstanding blockers and confirmed tech decisions. Never label as done/live/deployed without the evidence and user sign-off appropriate to that phase.
9. **No production risk by default:** current WordPress production is read-only until staging, backups, secure secrets handling, exact access controls and a reviewed rollout exist. No credentials, customer PII or live billing secrets in prompts, screenshots, Git, frontend bundles or logs. A separate Club database is preferred by the website technician; WooCommerce/PMPro remains authoritative for verified membership, FluentCRM for contacts/tags, Amazon SES for email delivery, and the Club DB for hashed QR IDs/redemption/history. First three months have manual monthly renewal, not automatic rebilling. A pending/unpaid order never grants a free coffee.
10. **User-facing answer style:** respond in Greek with practical, actionable steps. State **"Μοντέλο Claude Code: ... — γιατί ..."** immediately before any prompt. Provide one unambiguous prompt, not fragments, and after a report say precisely what the user should do next (e.g., paste in Claude Code, return report, review PR, or wait for staging). Don't ask the user to hand-edit source when Claude Code can make the change.

**Current development baseline at instruction date (2026-10-09):** Draft PR #1 on `feat/evangelou-club-plugin-foundation` contains inert WordPress plugin skeleton, unexecuted draft SQL schema and smoke-test CI. It is NOT merged, NOT installed in WordPress and NOT production-ready. Keep existing React ordering demo and /club mock untouched until an explicitly reviewed change.

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

## Read-only audit — Fluent Forms, FluentCart addons, WP timezone, 2026-10-08

- Fluent Forms → Forms screenshot: 3 active forms shown. **"Ευαγγέλου Club"** is form ID **3**, shortcode **[fluentform id="3"]**, with **186 / 186 entries** shown. The other forms (Subscription Form ID 2; Contact Form Demo ID 1) show zero entries. Never treat form entries as distinct persons or active paying memberships.
- Existing FluentCRM tag **"Ευαγγέλου Club"** displays 178 contacts; this differs from Fluent Forms form submissions (186). Investigate form integration, possible duplicate submissions, unsubscribes/unconfirmed contacts, and matching without exporting or disclosing customer PII. Do not infer the reason for the difference.
- FluentCart → Settings → Features & addon screenshot: **Cloudflare Turnstile inactive**, **Stock Management inactive**, **MCP for AI Agents setup required/off**, and optional addons offered for download (Elementor Blocks, Bricks Blocks, Divi Modules, Fluent PDF, Migrator, Customer Rights). No proof of a subscription-blocking addon requirement in this page.
- WordPress → Settings → General screenshot: public user self-registration unchecked. **Timezone set to fixed UTC+3**, NOT IANA **Europe/Athens**. A fixed UTC+3 will not account for Greece winter DST offset. The Club daily one-coffee limit must calculate calendar day with Europe/Athens timezone server-side, independent of browser, payment gateway and WP fixed offset. Do not change production WP timezone without impact review and approval.
- Next high-priority read-only audit: inspect **Fluent Forms → Forms → 'Ευαγγέλου Club' → Editor & Settings / Integrations / Marketing & CRM** to understand fields, consent, FluentCRM linkage and tag assignment; do not open real entry personal data or change configuration. Also check the form's embed location on the published site and the existing confirmation page/flow.
- Site WordPress settings screenshot includes administrative email address; do not preserve PII in project memory and remind user to redact when sharing future screenshots.

## Read-only audit — Fluent Forms Club form editor and confirmation, 2026-10-08

- User shared editor screenshot for existing **"Ευαγγέλου Club"** Fluent Forms form ID 3. Visible fields: first name required, surname required, mobile phone required, email **optional**, "Από που είσαι;" select appears optional, and marketing communication checkbox **required** ("Συναινώ στο να λαμβάνω επικοινωνία από το Ζαχαροπλαστείο Ευαγγέλου με email και SMS"). Submit button says **"ΔΩΡΕΑΝ ΕΓΓΡΑΦΗ"**. No customer records shown in these screenshots.
- Form Settings & Integrations → Confirmation Settings shows **Same Page** selected, confirmation text **"Είσαι μέσα! Σε ευχαριστούμε!"**, **Hide Form** selected after submission. No post-registration redirect to checkout/member QR is configured in this settings screen. Screenshot alone does NOT show the form's FluentCRM integration/feed settings: this remains unknown.
- Other displayed form configuration: no login requirement, scheduling or entry maximum restriction; advanced validation is Pro only. Avoid inferring actual CRM sync until Configure Integrations tab/feed is inspected.
- Important design decision: consent to receive marketing emails/SMS must be separate from Club subscription terms/payment and freely given; do not condition paid membership on promotional marketing consent. Mobile-only registrations may not have an email, so design robust unique identity/account linking and an intentional QR delivery/recovery flow; do not assume matching names or form entry IDs are sufficient for paid membership identity.
- Next safest read-only step: Fluent Forms → edit form ID 3 → **Settings & Integrations → Configure Integrations**; screenshot integrations/feed names and tag mapping, masking all tokens and personal data. Also inspect form field choices/confirmation only if needed; do not save or change live form.

## Read-only audit — Evangelou Club form FluentCRM integration feed, 2026-10-08

- Screenshot: Fluent Forms ID 3 ("Ευαγγέλου Club") → Settings & Integrations → Configure Integrations shows a **single enabled "FluentCRM Integration Feed"**.
- It establishes an enabled connection between the form and FluentCRM, but does not yet show whether all fields map correctly, whether the CRM tag is applied, whether a double-opt-in/consent condition is configured, or whether individual submissions successfully synced. Do not claim 186 submissions = 178 unique subscribed members.
- No separate Amazon/AWS integration is listed for this form; Amazon SES mention elsewhere appears related to mail sending/bounce handling, which still needs verification.
- Next read-only action: click the **green gear/configure icon** of the FluentCRM Integration Feed; inspect field mappings, selected list/tag, contact status and any conditional logic. **Do not click the blue duplicate icon, red delete icon, Enabled toggle or Save.** Mask any PII/secrets before sharing screenshots.

## Read-only audit — existing Club form's FluentCRM Integration Feed detail, 2026-10-08

- Screenshot of Fluent Forms ID 3 → Settings & Integrations → Configure Integrations → green gear → "Update FluentCRM Integration Feed".
- **Feed Name:** "FluentCRM Integration Feed"; status **Enable This feed = checked**.
- **FluentCRM List** selected: **"Ευαγγέλου Club"**.
- **Contact Tags** selected: **"Ευαγγέλου Club"**. The List and Tag are distinct FluentCRM objects with the same display name; neither proves paid membership.
- **Primary fields mappings:** FluentCRM Email Address ← form Email; First Name ← {inputs.names.first_name}; Last Name ← {inputs.names.last_name}; Full Name ← {inputs.names}. **Other fields:** Phone ← {inputs.input_text}; State ← {inputs.dropdown}. Email Address in this feed is marked required (*) but **form Email field is optional**; investigate handling of missing email as a potential (not proven) explanation for 186 form submissions vs 178 CRM contacts.
- **Dynamic Tag Selection = unchecked**. **Skip if contact already exists = unchecked**. **Skip name update if existing contact has old data = unchecked**. **Enable Double opt-in for new contacts = unchecked**. **Enable Force Subscribe if contact is not subscribed = unchecked**.
- **Conditional Logics** says upgrade to Pro to access advanced features; no conditional rule shown in the current feed. No explicit consent-based mapping shown in this settings screen. Marketing consent checkbox was required in original form; for paid membership onboarding, separate freely given marketing opt-in from payment/contract acceptance.
- No changes made, do not save/modify/delete existing production form or feed while auditing. Do not infer mail delivery or every contact sync succeeds without inspection.
- Architecture implications: use FluentCRM as contact/marketing source, **actual subscription/billing system** for entitlements, custom WordPress Club plugin for opaque QR IDs and daily redemption locking/history. Do not treat the legacy "Ευαγγέλου Club" list/tag as Active Paying Member. Consider distinct tags for newsletter-only, active, and lapsed members after mapping and approval, and keep opt-out/consent status independent of billing membership status.
- Next read-only inspection: FluentCRM → Contacts → Lists (list titles/aggregate counts only) and/or inspect public placement of Fluent Forms ID 3. Need client's technician response for existing billing, AWS sending and staging.

## TECHNICIAN CONFIRMED ARCHITECTURE — 2026-10-08 (supersedes assumptions)

Received a direct reply from the existing website developer:

- The intended e-commerce platform is **WooCommerce, NOT FluentCart**. WooCommerce is **not yet installed** in the inspected WP plugin list. Do not implement Club payments on FluentCart.
- They intend to install and use **Paid Memberships Pro (PMPro)** together with **WooCommerce** to sell the €20/month Club subscription. PMPro also **not yet installed** on audited site. The setup will be created by the website developer.
- **No paid memberships, paying members, or live subscription billing exist yet.** All legacy 178 FluentCRM contacts are **newsletter subscribers only** and must never receive daily free coffee entitlement solely for being in the original list/tag.
- The developer proposes payment methods **Viva, PayPal, bank transfer, cash at the shop**. There is currently **no Stripe or other existing merchant setup** identified for subscriptions. Automatic recurring billing for Viva/PayPal is NOT yet confirmed.
- Newsletter registration currently uses **Fluent Forms → FluentCRM**. Contact lists and tags are managed **only in FluentCRM**. No other contact-sync automation exists.
- Amazon service is **Amazon SES**, for email delivery. Do NOT try to manage CRM tags in Amazon SES; use FluentCRM.
- Need confirmation who installs/configures WooCommerce, PMPro, WooCommerce Integration add-on, gateway plugins, and whether WooCommerce Subscriptions is purchased/used.
- Official PMPro documentation for selling PMPro memberships through WooCommerce says to install their free WooCommerce Integration add-on and map WooCommerce membership products to PMPro levels, and requires WooCommerce Subscriptions (or another stated supported recurring billing path) for recurring WooCommerce membership charging. WooCommerce completion/paid status should control when PMPro level is granted. Avoid granting membership on pending bank transfer or cash orders.
- Official Viva developer docs describe recurring support via WooCommerce Subscriptions, but current WooCommerce.com documentation may differ on recurring compatibility; validate against the EXACT selected gateway extension/version via end-to-end staging test, not assumption. Bank transfer and cash are manual-payment channels and need deliberate paid verification, renewal and expiration handling.
- The Club plugin must check effective, verified paid membership status from authoritative PMPro/WooCommerce lifecycle, never FluentCRM tags alone. Prevent duplicate coffee redemption via atomic per-member per-Athens-day records with a unique constraint; no real data mutations during audit.
- Ask technician for specific integration architecture/ownership and timeline; then work in staging/backup before custom plugin development. Scope remains Phase 1 Club only, €1,500 + VAT, aiming early December 2026.

## FINAL TECHNICIAN DECISIONS — 2026-10-09 (supersede previous recurring-payment assumptions)

The existing website developer replied explicitly:
- **They will install and configure WooCommerce and Paid Memberships Pro (PMPro)**, create the €20/month membership product, and map it to the PMPro membership level.
- **No automatic monthly renewal during the first ~3 months**. This is an intentional business decision to avoid forgotten recurring payments, cancellations and refunds. They may review recurring billing after ~3 months. Do not design or advertise initial automatic monthly card charges.
- Accepted payment methods: Viva, PayPal, bank transfer and cash at the shop as previously confirmed. **Bank-transfer/cash Club access activates only after staff confirms payment**. The developer explicitly confirms no automatic renewal. Other payment confirmation must likewise be verified; an unpaid/pending WooCommerce order must never grant coffee entitlement.
- Club plugin needs to recognize **active, expired, canceled** membership and synchronize the appropriate **FluentCRM tags**. Developer asks whether unpaid needs handling: architecturally **yes, account/order pending payment should be ineligible**, but no need to create a marketing "unpaid" CRM tag unless useful; use pending-payment internally.
- Developer **prefers a separate database for our custom Club plugin**, and confirms they can create one. This separate DB is for custom QR identifiers, redemption ledger, coffee-type history and audit events, **not** for copying the full WordPress / WooCommerce / PMPro / FluentCRM datasets. Official WordPress developer documentation supports a second `wpdb` instance for separate DB access. Define separate narrow DB credentials, backup/restore and encrypted config delivery; do not place secrets in GitHub or chat. Perform membership lookups through WP/PMPro APIs rather than querying internal tables from separate database.
- Hosting provider offers **no built-in staging**; developer says a staging clone/site **can be created via a plugin**. Must establish an isolated staging environment (safe URL/access, mail suppression, test/sandbox gateways, no live webhooks or real billing, sanitized customer data), have backup and restoration plan before write operations.
- Important verified PMPro WooCommerce integration documentation: WooCommerce Integration Add On can map a **non-recurring WooCommerce membership product to a PMPro level**, with membership granted on order **completed** when auto-complete is off. Explicitly configure a **one-month membership expiration** and test repeated manual purchases/extensions, cash/bank payment confirmation, refunds/cancellations and boundary dates. WooCommerce Subscriptions is required for **recurring payments via WooCommerce**, but not necessarily for initial manual one-month memberships. Source: https://www.paidmembershipspro.com/gateway/woocommerce/
- Primary identity/status sources: WooCommerce/PMPro for verified payment and eligibility, FluentCRM for newsletter marketing consent & segmentation, separate Club database for opaque QR tokens and redemption/history. Daily one-redemption-per-member-per-Europe/Athens-calendar-day must be protected atomically at DB level; no access granted solely by CRM tag.
- Scope and finances unchanged: Evangelou Club Phase 1 ONLY, **€1,500 + VAT**, first year support free, thereafter €300 + VAT/year, and potential infrastructure charged only if approved. Deadline target early December 2026 subject to staging and proper access.
- Plan: ask developer to tell us once WooCommerce + PMPro + WooCommerce Integration mapping are installed in staging, staging URL/access is ready, and separate database is provisioned, including sanitized configuration and test accounts (never share credentials in chat). Meanwhile build isolated plugin scaffold with mock membership adapter and migration/redeem QA tests without touching production.
