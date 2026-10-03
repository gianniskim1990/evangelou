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
