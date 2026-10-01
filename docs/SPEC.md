# Build a restaurant online ordering platform

You are a senior full-stack engineer. Build a production-quality MVP of a direct online ordering platform for takeaway restaurants: the branded ordering app and website a restaurant uses so customers can order from it directly instead of only through Uber Eats or DoorDash.

Read this whole spec before starting. It has four parts:

1. **Backend API**: Laravel + MySQL
2. **Customer app**: React Native with Expo, shipped from one codebase to iOS, Android and the web
3. **Kitchen screens**: staff screens inside the same Expo app, used on a tablet
4. **Owner back office**: Filament, inside the Laravel app

Start with one restaurant, but design everything so more restaurants can be added later without a rewrite.

## Project settings

| Setting | Value |
|---|---|
| Demo restaurant | Himalayan Momo House (fictional) |
| Cuisine | Nepalese takeaway: momos, chow mein, thukpa, sides, drinks |
| Timezone | Australia/Melbourne |
| Currency and tax | AUD; menu prices include 10% GST |
| Fulfilment | Pickup and delivery |
| Delivery area | Postcodes 3000, 3006, 3008; flat fee $6.00; minimum order $25.00 |
| Brand colour | #7A1F2B |
| Payments | Stripe (test mode), including Apple Pay and Google Pay |
| Production domains | Website: example-restaurant.com.au; API: api.example-restaurant.com.au |

## Tech stack (use exactly this)

### Backend
- Latest stable Laravel, with the PHP version it requires; MySQL 8 (InnoDB, utf8mb4)
- Laravel Sail for local development (MySQL, Mailpit)
- Laravel Sanctum bearer tokens for API authentication; set up API routes the way the current Laravel docs describe (e.g. `php artisan install:api`)
- Laravel Reverb for realtime updates (`php artisan install:broadcasting --reverb`)
- Database queue driver for emails, push notifications and webhook processing; Laravel scheduler for timed jobs
- `stripe/stripe-php` for payments (not Laravel Cashier, which is for subscriptions)
- Filament (latest stable) for the owner back office at `/admin`
- Pest for tests, Laravel Pint for formatting, Larastan for static analysis

### Frontend
- Latest stable Expo SDK, TypeScript strict mode, Expo Router (file-based routing)
- Use Expo development builds (EAS), not Expo Go, because push notifications and wallet payments need native configuration
- NativeWind for styling and Moe UI components, installed with its CLI (`@moe-ui/cli`; check moe-ui-docs.vercel.app for the exact commands). If a Moe UI component misbehaves on iOS or Android, replace it with the React Native Reusables equivalent and note it in `docs/DECISIONS.md`
- TanStack Query for server data; Zustand for the cart (persisted)
- React Hook Form + Zod for forms
- `laravel-echo` + `pusher-js` for Reverb (use pusher-js's React Native build on native)
- Stripe: `@stripe/stripe-react-native` PaymentSheet on iOS/Android, `@stripe/react-stripe-js` Payment Element on web. Put them in `PaymentForm.native.tsx` and `PaymentForm.web.tsx` behind one shared interface. Merchant country AU, currency AUD. Apple Pay needs an Apple Merchant ID set in the Stripe config plugin
- `expo-notifications` for push, `expo-secure-store` for tokens on native, `expo-image` for images, `expo-keep-awake` for the kitchen screen

Don't add other major libraries without asking. Before installing anything, check its current docs for the correct install command and version, and pin versions in `composer.json` and `package.json`.

## Repository layout

```
/backend      Laravel app
/app          Expo app
/docs         SPEC.md (this prompt), API.md, DECISIONS.md, SETUP.md
```

Save this prompt as `docs/SPEC.md` and re-read it at the start of each phase. Record every judgement call in `docs/DECISIONS.md`.

## Data model (MySQL)

Every restaurant-owned table has a `restaurant_id` foreign key, and every query is scoped by it. Money is stored as integer cents. Timestamps are stored in UTC.

- `restaurants`: name, slug (unique), custom_domain (nullable, unique), timezone, currency, phone, email, address, logo, brand_color, is_accepting_orders, pickup_enabled, delivery_enabled, default_prep_minutes, auto_reject_minutes
- `restaurant_user` (pivot): restaurant_id, user_id, role (`owner` | `staff`)
- `users`: name, email (unique), phone, password. Customers and staff share this table
- `opening_hours`: day_of_week (0–6), opens_at, closes_at. A closing time earlier than the opening time means the restaurant closes after midnight
- `special_hours`: date, is_closed, opens_at, closes_at (public holidays and one-off changes)
- `menu_categories`: name, description, sort_order, is_active
- `menu_items`: category_id, name, description, price_cents, image, dietary_tags (json), allergens (json), is_available (sold-out switch), is_active, sort_order
- `modifier_groups`: name (e.g. "Choose filling"), min_select, max_select, sort_order
- `modifier_options`: modifier_group_id, name, price_delta_cents, is_available, sort_order
- `menu_item_modifier_group` (pivot): menu_item_id, modifier_group_id, sort_order
- `delivery_zones`: name, postcodes (json), fee_cents, min_order_cents, estimated_minutes, is_active
- `promo_codes`: code, type (`percent` | `fixed`), value, min_order_cents, starts_at, ends_at, max_uses, uses_count, is_active
- `customer_addresses`: user_id, label, line1, line2, suburb, state, postcode, delivery_instructions
- `orders`: public_id (ULID), order_number (short daily number per restaurant such as 042, generated safely when orders arrive at the same moment), user_id (nullable for guests), status, payment_status, fulfilment_type (`pickup` | `delivery`), scheduled_for (null means ASAP), customer name/phone/email, delivery address snapshot, subtotal_cents, delivery_fee_cents, discount_cents, total_cents, gst_cents, promo_code_id, notes, prep_minutes, estimated_ready_at, stripe_payment_intent_id (unique), idempotency_key (unique), tracking_token, accepted_at, ready_at, completed_at, cancelled_at, rejection_reason, push_token (nullable, for guests)
- `order_items`: order_id, menu_item_id, name (snapshot), unit_price_cents, quantity, line_total_cents, notes
- `order_item_modifiers`: order_item_id, modifier_option_id, name (snapshot), price_delta_cents
- `order_status_events`: order_id, from_status, to_status, user_id (nullable), note. This is the full audit trail
- `stripe_events`: stripe_event_id (unique), type, processed_at, so a webhook is never processed twice
- `push_tokens`: user_id (nullable), expo_push_token (unique), platform

Index every foreign key, plus `orders(restaurant_id, status, created_at)`.

## Business rules

1. **The server is the only source of truth for prices.** The app sends item IDs, quantities, chosen option IDs, fulfilment type, postcode and address, scheduled time and promo code. The API checks availability, modifier min/max rules, opening hours, delivery zone, minimum order and promo validity, then calculates every total itself. Never trust amounts sent by the app.
2. **Opening hours** are interpreted in the restaurant's timezone, including after-midnight closing and special hours. ASAP orders are allowed only while the restaurant is open and accepting orders. Scheduled orders must fall inside opening hours, in 15-minute slots, up to 7 days ahead.
3. **Order lifecycle:** `pending_payment → placed → accepted → preparing → ready → (out_for_delivery) → completed`, plus `rejected` and `cancelled`. Implement this as a state machine in one service and reject every other transition. Record each change in `order_status_events`.
4. **Payment flow:**
   - `POST /restaurants/{slug}/orders` creates the order as `pending_payment` plus a Stripe PaymentIntent for the server-calculated total, and returns the client secret. It requires an `Idempotency-Key` header; repeating a key returns the original order instead of creating a new one. Send an idempotency key to Stripe as well.
   - Only the verified Stripe webhook (`payment_intent.succeeded`) marks the order paid and moves it to `placed`. Verify the webhook signature and skip any event already stored in `stripe_events`.
   - A scheduled job cancels `pending_payment` orders older than 30 minutes.
5. **Kitchen actions:** staff accept an order with a prep time (which sets `estimated_ready_at`) or reject it with a reason. Rejecting an order, or cancelling a paid one, refunds it in full through Stripe. Orders not accepted within `auto_reject_minutes` are rejected and refunded automatically, and the customer is notified.
6. **Pausing:** staff can pause ordering and mark items or options sold out. The menu API reflects this immediately.
7. **GST:** menu prices include GST. Store `gst_cents` as the total divided by 11, rounded to the nearest cent, and show it on receipts.
8. **Notifications:** on every status change, broadcast the update and send an Expo push notification (via Expo's push API) if a push token exists. Email a confirmation when an order is placed. Queue all of these.
9. **Privacy:** customers can delete their account in the app; anonymise their past orders rather than deleting them. Never broadcast personal details: order channels carry status and times only.

## API (REST, JSON, prefix `/api/v1`)

Use Form Requests for validation, API Resources for responses, Policies for authorisation, and one consistent error format (`message` plus field-level `errors`). Rate-limit login, order creation and promo checks. Configure CORS for the web app's origin. Document every endpoint in `docs/API.md` with example requests and responses.

Public:
- `GET /restaurants/{slug}`: details, open-now status, next opening time, fulfilment options
- `GET /restaurants/{slug}/menu`: active categories → items → modifier groups → options
- `POST /restaurants/{slug}/delivery-check`: postcode → zone, fee, minimum order and ETA, or "not deliverable"
- `POST /restaurants/{slug}/orders/quote`: cart → validated line items, totals and any errors
- `POST /restaurants/{slug}/orders`: create order + PaymentIntent (requires `Idempotency-Key`)
- `GET /orders/{public_id}?token=…`: order tracking (guests use the tracking token)
- `POST /stripe/webhook`

Customer (signed in):
- `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /me`, `DELETE /me`
- `GET|POST|PATCH|DELETE /me/addresses`
- `GET /me/orders` (paginated), `POST /push-tokens`

Staff (signed in, with a staff or owner role for that restaurant):
- `GET /staff/orders?status=`: live order queue for their restaurant
- `POST /staff/orders/{id}/accept` (`prep_minutes`), `POST /staff/orders/{id}/reject` (`reason`), `POST /staff/orders/{id}/status`
- `PATCH /staff/restaurant` (pause or resume ordering)
- `PATCH /staff/menu-items/{id}` and `PATCH /staff/modifier-options/{id}` (sold out)

Realtime channels (Reverb):
- `private-restaurant.{id}.orders`: new and updated orders for staff
- `order.{public_id}`: status and times only, for the customer's tracking screen

If the socket disconnects, the app falls back to polling every 10 seconds.

## Owner back office (Filament, `/admin`)

Owners manage restaurant settings and branding, opening and special hours, menu categories, items (with image upload), modifier groups and options, delivery zones, promo codes, staff accounts, and orders (view, filter, refund). Each user sees only their own restaurant's data.

## Expo app

### Customer screens (Expo Router)
- `/`: restaurant header with open/closed status and next opening time, pickup/delivery switch, category tabs, menu list with photos, prices and sold-out state, and a sticky "View cart" bar showing item count and total
- `/item/[id]`: bottom sheet (a modal on web) with options, min/max rules shown inline, quantity, notes and "Add to cart"
- `/cart`: edit items, promo code, pickup or delivery, ASAP or a scheduled time
- `/checkout`: contact details (guest or signed in), delivery address with postcode check, totals from the quote endpoint, then payment with Stripe
- `/order/[publicId]`: live status timeline, estimated ready or delivery time, "Order again"
- `/account`: sign in or register, order history, saved addresses, delete account

### Kitchen screens (`/staff`, tablet-first, staff roles only)
- `/staff/login`
- `/staff/orders`: columns for New, Preparing, Ready and Out for delivery. Browsers block sound until the user interacts, so the board opens with a "Start shift" button that enables alerts. New orders play a repeating alert and stay highlighted until accepted. Staff accept with one-tap prep times (10, 15, 20 or 30 minutes) or reject with a reason. Keep the screen awake (`expo-keep-awake` on native, the Screen Wake Lock API on web)
- `/staff/settings`: pause ordering, sold-out switches

### App architecture
- One typed API client with an auth interceptor; keep request and response types in sync with `docs/API.md` (shared Zod schemas are fine)
- Token storage: `expo-secure-store` on iOS/Android; on web, keep the token in memory with a localStorage fallback, and note the trade-off in `docs/DECISIONS.md`
- Configuration in `app.config.ts` from `EXPO_PUBLIC_*` variables (API URL, Reverb host and key, Stripe publishable key). Never put secrets in `EXPO_PUBLIC_*` variables
- Load the restaurant's name, logo and brand colour from the API at start-up, so the same code can serve other restaurants later
- Loading, empty and error states on every screen; an offline banner; pull-to-refresh on the menu and order screens

### Web and SEO
- Render the public menu pages on the server using Expo Router server rendering with data loaders. If the installed SDK doesn't support that, use static rendering; check the current Expo docs. Set page titles, descriptions and Open Graph tags with `expo-router/head`
- Add Restaurant and Menu JSON-LD structured data
- Add a web manifest and icons so the site can be installed to a phone's home screen
- The layout works from 360px phones up to desktop; on wide screens, show the cart as a side panel

### Design direction
Before building screens, propose a compact design plan and wait for my approval: 4–6 named colours built around the brand colour, the typefaces and their roles, the layout concept, and 2–3 guiding principles. Ground it in the restaurant's cuisine and food photography rather than a generic delivery-app template. Avoid defaults such as identical rounded cards with soft grey shadows everywhere, all-caps labels above headings, and decorative gradients. Put the boldness in one place (for example the food photos or the header) and keep everything else calm.

Minimum requirements: 44px tap targets, WCAG AA contrast, visible focus states on web, reduced-motion support, and dark mode.

UI copy: plain, active verbs ("Add to cart", "Place order", "Accept order"). An action keeps the same name throughout the flow. Error messages say what went wrong and how to fix it. Empty states tell people what to do next.

## Seed data

A demo restaurant using the project settings above:
- 5 categories and about 20 items: steamed, fried and jhol momos, chow mein, thukpa, sides and drinks, with realistic prices
- Modifier groups: "Choose filling" (chicken, pork or vegetable; required, pick 1), "Sauce" (optional, up to 2) and "Spice level" (required, pick 1)
- Opening hours 5pm–10pm daily, until 11pm on Friday and Saturday
- The delivery zone, one promo code, one owner account and one staff account (list the demo credentials in `docs/SETUP.md`)

## Quality bar

- Business logic lives in service classes (`PricingService`, `OrderService` with the state machine, `OpeningHoursService`, `DeliveryService`); controllers stay thin
- Pest tests cover: price calculation with modifiers and promos, modifier min/max validation, opening hours (after-midnight and special hours), delivery zones, every allowed and forbidden status transition, idempotent order creation, webhook signature checks and replay protection, auto-reject with refund, and staff being denied access to other restaurants
- Pint, Larastan, ESLint and `tsc --noEmit` all pass
- `.env.example` files for both apps; secrets are never committed
- `docs/SETUP.md` explains how to run everything locally: Sail, migrations and seeders, the queue worker, the scheduler, Reverb, forwarding Stripe webhooks with the Stripe CLI, and running the Expo app on iOS, Android and web. Add short deployment notes: EAS Hosting for the web app, and a server that can run PHP, MySQL, queue workers, the scheduler and Reverb for the API

## How to work

Build in the phases below. At the start of each phase, give a short plan. At the end, run the tests and linters and fix any failures. Then summarise what you built, list the files you changed, note anything I need to do (API keys, accounts), and **stop and wait for me to reply "continue"**.

If something in this spec is unclear, choose the simplest option that fits, record it in `docs/DECISIONS.md`, and keep going. Ask me only if the choice would be hard to undo.

1. **Backend foundation:** Laravel, Sail, MySQL, Sanctum, migrations, models, factories, seeders, Filament back office
2. **Menu and pricing API:** restaurant, menu, opening hours, delivery check, quote endpoint, pricing engine, tests
3. **Orders and payments:** order creation, Stripe PaymentIntents and webhook, state machine, refunds, auto-reject, Reverb broadcasting, push and email jobs, staff endpoints, tests
4. **Expo foundation:** project setup, NativeWind and Moe UI, design plan (wait for approval), API client, auth, theming from the restaurant settings
5. **Customer flow:** menu, item sheet, cart, checkout with Stripe on native and web, order tracking with realtime updates and push
6. **Kitchen screens:** live order board with alerts, accept and reject, pause and sold-out controls
7. **Web, SEO and polish:** server-rendered menu pages, structured data, web manifest, responsive layout, accessibility pass, README, final test run

## Definition of done

- On an iOS simulator, an Android emulator and a desktop browser, a customer can browse the seeded menu, add items with options, and pay with the Stripe test card 4242 4242 4242 4242
- The order appears on the kitchen screen within 2 seconds with an alert sound; staff accept it; the customer's tracking screen updates live, and a push notification arrives on a real device
- Changing prices or totals in a request can't change the amount charged
- Replaying a Stripe webhook or resending an order request never creates duplicates
- An ASAP order can't be placed while the restaurant is closed or paused
- All tests and linters pass
