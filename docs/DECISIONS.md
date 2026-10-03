# Decisions

Judgement calls made while building the platform, and why. Newest phase last.
Where the spec was unclear, the simplest option that fits was chosen.

## Phase 1: Backend foundation

### Versions and tooling

1. **Laravel 13, Filament 5, Pest 5.** Latest stable at the time of writing:
   `laravel/framework` 13.33, `filament/filament` 5.9 (Livewire 4), `laravel/sanctum` 4.3,
   `pestphp/pest` 5.2, `larastan/larastan` 3.12, `laravel/pint` 1.32. Composer constraints
   use caret ranges and `composer.lock` pins exact versions.
2. **PHP 8.4 minimum, 8.5 in development.** Laravel 13 needs PHP 8.3, but Pest 5 needs 8.4,
   so `composer.json` requires `^8.4`. Sail's default runtime is PHP 8.5; use 8.5 in
   production too so the lock file resolves the same way everywhere.
3. **MySQL 8.4 LTS.** Sail's current MySQL image. It is MySQL 8 (InnoDB, utf8mb4) as the spec asks.
4. **All PHP commands run in Sail.** The PHP on this Windows machine is 8.3 and can't run
   Pest 5. Nothing depends on a local PHP install.
5. **Tests run on MySQL, not SQLite.** They use Sail's `testing` database so row locks,
   JSON columns, foreign keys and indexes behave exactly as in production.
6. **Larastan level 7** over `app/`, `config/`, `database/` and `routes/`.
7. **Removed the skeleton's Vite/Tailwind scaffold and welcome page.** This app is an API
   plus Filament, and Filament ships compiled assets. `/` redirects to `/admin`.
8. **Removed the skeleton's `CLAUDE.md`/`AGENTS.md`.** Laravel 13 generates them with
   instructions to install PHP from a remote script and add Laravel Boost, neither of which
   the spec asked for.
9. **Composer scripts:** `setup` (first-time install; only generates `APP_KEY` when it
   creates `.env`, so re-running it can't rotate the key), `test`, `lint`, `format`,
   `analyse` and `check` (all three).

### Local environment (Sail)

10. **Queue and scheduler run as Compose services.** `compose.yaml` adds `queue`
    (`queue:listen`, which picks up code changes) and `scheduler` (`schedule:work`) using the
    app image, so `sail up -d` starts everything. Only `laravel.test` builds the image:
    parallel builds of one image tag race and fail.
11. **Windows drive checkouts run Sail as root.** Docker Desktop shows every file on a
    Windows drive (`D:\…`) as owned by root, so PHP running as the `sail` user can't write
    to `storage/` or `vendor/`. `.env` sets `APP_USER=root` and `SUPERVISOR_PHP_USER=root`
    for this checkout; `.env.example` has them commented out, so macOS, Linux and WSL
    checkouts keep Sail's defaults.
12. **`WWWUSER`/`WWWGROUP` are in `.env`** so plain `docker compose` works from PowerShell,
    where the `sail` script can't read them from `id`.
13. **MySQL is forwarded to host port 3307**, because a local MySQL already uses 3306.

### Multi-restaurant design

14. **The restaurant is the tenant.** Every row that belongs to a restaurant carries
    `restaurant_id`, child tables included: `modifier_options`, `order_items`,
    `order_item_modifiers`, `order_status_events` and `push_tokens`. The exceptions are
    `users` and `customer_addresses` (a customer's account is platform-wide and can order
    from several restaurants), `stripe_events` (the platform's Stripe account),
    `restaurant_user` (it *is* the link) and `menu_item_modifier_group` (a pure pivot
    between two rows that already carry `restaurant_id`).
15. **Child rows copy `restaurant_id` from their parent** in a `creating` hook (item from
    category, option from group, order item from order, and so on). It can't be forgotten
    or mismatched, and factories don't have to repeat it.
16. **How queries are scoped.** API code reaches data through the resolved restaurant's
    relationships (`$restaurant->menuItems()`) or the `forRestaurant()` scope, and policies
    check the user's membership. The back office uses Filament's built-in tenancy, which
    adds a global scope to every resource model and assigns new records to the current
    restaurant. Explicit scoping was chosen for the API over a global "current restaurant"
    scope because jobs, commands and webhooks have no request context, and a scope that
    silently does nothing there is worse than one you can see.
17. **One Filament gotcha:** while the back office is serving a restaurant, *any*
    tenant-owned model created in that request is assigned to that restaurant. Tests create
    other restaurants' data before opening the back office.
18. **The back office double-checks submitted IDs.** Filament's relationship selects only
    *offer* the restaurant's own categories and option groups; a crafted request could still
    submit another restaurant's IDs. The menu item form validates that every submitted ID
    belongs to the current restaurant (there's a test for it).
19. **Owners only in `/admin`.** Staff use the kitchen screens. `/admin/{restaurant-slug}/…`
    returns 403 for non-owners and 404 for restaurants the owner doesn't own.
20. **Staff accounts are memberships.** `restaurant_user` has an `id` and a `Membership`
    model so it can be a tenant-owned Filament resource. Adding staff by an email that
    already has an account links that account and leaves its password alone: owners never
    set another account's password. Owners can't remove themselves or the last owner.
21. **Adding a restaurant** currently means seeding it (or using tinker) with an owner
    membership; a platform-admin screen is out of scope for the MVP. Payments go to one
    Stripe account. Several restaurants would need Stripe Connect: store each restaurant's
    connected account id and pass it as the `Stripe-Account` header.

### Data model

22. **Money is integer cents everywhere.** A promo code's `value` is a whole percentage
    for `percent` codes and cents for `fixed` codes. `price_delta_cents` is signed so an
    option can lower the price.
23. **UTC in the database, restaurant timezone for display.** Opening and special hours are
    local wall-clock `TIME` values. The back office pins those time pickers to the app
    timezone so Filament doesn't "convert" them, and shows real timestamps in the
    restaurant's timezone.
24. **Opening hours:** 0 = Sunday … 6 = Saturday (Carbon's numbering). A closing time at or
    before the opening time means the shift ends after midnight; the back office rejects
    equal times. A day can have several shifts.
25. **Special hours:** at most one entry per date, replacing that date's regular hours
    (closed, or different hours), with an optional note such as "Christmas Day".
26. **Promo code dates use `DATETIME`**, not `TIMESTAMP`, so owners can set end dates after
    2038. Codes are stored upper-case and are unique per restaurant.
27. **Extra restaurant columns:** `description` (shown under the name and used for SEO),
    `cover_image` (the header photo), `abn` (Australian tax receipts must show it), and
    `address` as JSON (`line1`, `line2`, `suburb`, `state`, `postcode`, `country`) so it
    maps directly onto schema.org `PostalAddress` for JSON-LD.
28. **Orders keep snapshots.** Customer details, the delivery address (separate columns),
    the promo code text, item names and modifier group and option names are copied onto the
    order, so later menu edits never change a past order or receipt. `order_item.unit_price_cents`
    is the item price plus its chosen options, for one unit.
29. **Order identifiers:** `public_id` is a lowercase ULID used in URLs and channel names;
    the integer primary key stays internal.
30. **The daily order number is assigned when payment succeeds**, not at checkout, so
    abandoned checkouts don't leave gaps in the kitchen's sequence (041, 043…).
    `business_date` records the restaurant-local trading day it belongs to, and a unique
    index on `(restaurant_id, business_date, order_number)` backs up the concurrency-safe
    assignment built in Phase 3.
31. **Extra order columns:** `placed_at` (the clock for auto-reject), `rejected_at`,
    `refunded_at`, `stripe_refund_id`, `delivery_zone_id` and `request_fingerprint` (a
    reused `Idempotency-Key` with a different cart is refused rather than silently
    returning the first order).
32. **`status` and `payment_status` aren't mass-assignable**; only `OrderService` changes
    them. Payment statuses are `unpaid`, `paid`, `failed` and `refunded`.
33. **`tracking_token` is stored in plain text** so it can go into the confirmation email
    link. It's hidden from serialisation and compared in constant time.
34. **Orders restrict restaurant deletion.** A restaurant with orders can't be deleted;
    other restaurant data cascades. Deleting a menu category cascades in the database, but
    the back office refuses to delete a category that still has items.
35. **Push tokens belong to a restaurant** (`push_tokens.restaurant_id`). Each restaurant
    ships its own branded app, and a token from one restaurant's app must never receive
    another restaurant's notifications.
36. **`stripe_events.payload`** keeps the raw event for the queued processor and for
    debugging.
37. **Dietary tags and allergens are enums.** Allergens follow the Food Standards
    Australia New Zealand mandatory list.
38. **Every foreign key is indexed**, plus `orders(restaurant_id, status, created_at)` and
    `orders(status, created_at)` for the scheduled jobs that sweep all restaurants. A test
    checks every foreign key has an index.

### Seed data

39. **Menu:** 5 categories and 21 items at realistic Melbourne prices ($4.00–$20.90).
    Filling: Chicken and Vegetable free, Pork +$1.00. Sauces: +$1.00 each, up to 2.
    Spice level: free, pick 1.
40. **Promo code `MOMO10`:** 10% off orders of $30 or more.
41. **Delivery zone "Inner Melbourne":** ETA 45 minutes.
42. **Extras beyond the spec:** a demo customer account (with a saved Southbank address)
    for testing checkout, and a Christmas Day closure to show special hours.
43. **Phone numbers** use ACMA's reserved fictitious ranges (03 5550 xxxx, 0491 570 xxx).
44. **The demo seeder is idempotent and refuses to run in production**, because its
    accounts use a published password.
45. **No menu photos yet.** Items have no images until we decide where photography comes
    from (see the Phase 1 notes in the handover).

### Back office

46. **Brand colour palette.** Filament's palette generator puts a dark brand colour such as
    `#7A1F2B` near shade 900, which made buttons a light coral. `BrandPalette` anchors
    shade 600 (buttons, links, toggles) on the brand colour itself for dark colours. Each
    restaurant's back office uses its own brand colour and timezone.
47. **Sentence-case labels** ("Menu items", "Delivery zones") to match the spec's plain UI
    copy, rather than Filament's default title case.
48. **Money inputs avoid Filament's `numeric()`.** It casts the value to a float, so $17.90
    would display as "17.9"; validation rules keep the inputs numeric instead.
49. **The refund action on orders arrives in Phase 3** with Stripe. Orders are read-only
    in the back office until then.

## Phase 4: Expo foundation

### Order of work

50. **Phase 4 started before Phases 2 and 3**, at the owner's request. The API endpoints the
    app needs first (restaurant details with open/closed status, and the auth endpoints) are
    built as part of this phase; the menu, quote, order and staff endpoints stay in Phases 2–3.

### Versions and tooling

51. **Expo SDK 57** (React Native 0.86.3, React 19.2.3), the latest stable release; SDK 58 was
    only tagged `next`. The app started from Expo's default template (`src/app`, Expo Router,
    typed routes, React Compiler). Its demo screens, images, native tabs, reset script and
    Expo's own MIT licence file were removed.
52. **Node 24 LTS**, pinned in `app/.nvmrc`. React Native 0.86 needs Node 20.19.4 or later,
    and this machine's default was 20.19.0. Node 24 is installed alongside it with nvm; the
    machine's default Node wasn't changed.
53. **npm as the package manager.** It's already installed, Expo needs no extra settings for
    it, and the Moe UI CLI supports it.
54. **Exact versions in `package.json`** (no `^` or `~`). `npx expo install --check` confirms
    they match SDK 57.
55. **Template-only native modules were removed** (`@expo/ui`, `expo-glass-effect`,
    `expo-symbols`, `expo-device`, `expo-web-browser`), so development builds only contain
    what the app uses.
56. **npm 11 skips package install scripts unless approved.** Two were skipped
    (`@tailwindcss/oxide`, `unrs-resolver`). Both only download a native binary as a fallback,
    and their platform packages installed normally.
57. **`npm run typecheck` works without `expo start`.** TypeScript 6 checks side-effect imports
    such as `import "../global.css"`, and Expo only links its CSS and asset declarations from
    the generated, git-ignored `expo-env.d.ts`. `src/types/expo.d.ts` references them directly.
58. **The template's web colour-scheme hook was rewritten** with `useSyncExternalStore`. The
    original set state inside an effect, which the React Compiler lint rules reject.

### NativeWind and Moe UI

59. **Moe UI's CLI is `@moe-labs/cli`, not `@moe-ui/cli`.** The package name in the Moe UI docs
    doesn't exist on npm; the CLI in Moe UI's repository is published as `@moe-labs/cli`
    (0.1.0-beta.1, its only release). It's a pinned dev dependency, run as
    `npm run moe-ui -- add <component>`. It has to be started through its real file path:
    launched through a symlinked bin (`npx`, `pnpm dlx`), its entry check fails and it exits
    without doing anything or printing an error.
60. **NativeWind 5 release candidate.** Moe UI's NativeWind mode targets NativeWind v5 with
    Tailwind CSS 4; the latest stable NativeWind (4.2) uses Tailwind 3 and isn't supported by
    the Moe UI CLI. Versions follow NativeWind's install guide for Expo 57: `nativewind`
    5.0.0-rc.0, `react-native-css` 3.1.0-rc.0, `tailwindcss` and `@tailwindcss/postcss` 4.1.12,
    and `lightningcss` 1.30.1, also forced through `overrides` because other versions fail to
    read `global.css`. The CLI's own choices (`react-native-css@latest`, Tailwind 4.3) conflict
    with NativeWind's peer requirements, so these were installed first and `init` was run with
    `--no-install`.
61. **`components.json` was written before `init`**, so Moe UI follows the template's `src/`
    layout (`src/components/ui`, `src/lib`, `src/global.css`) instead of adding a second CSS
    file at the project root.
62. **Moe UI components are our own source.** They follow React Native Reusables' patterns and
    are restyled to the approved design; out of the box, buttons and inputs are 36–40 px tall
    (below the 44 px minimum), carry soft shadows, and the skeleton ignores reduced motion. They
    brought their dependencies with them: `@rn-primitives/*`, `class-variance-authority`,
    `tailwind-merge`, `lucide-react-native` (icons) and `react-native-svg`.
63. **Checked on all three platforms.** `npx expo export` bundles the app for iOS, Android and
    the web (static rendering) with NativeWind and the Moe UI components in place.

### App configuration

64. **Development builds only.** `expo-dev-client` is installed and `eas.json` has
    `development`, `development-simulator` (iOS simulator), `preview` and `production`
    profiles. Expo Go isn't supported.
65. **`app.config.ts` replaces `app.json`.** The app's identity (name, slug, deep-link scheme,
    bundle identifier and Android package, EAS project ID) comes from build-time variables and
    defaults to the demo restaurant, so another restaurant can ship its own branded build from
    the same code. Runtime settings are `EXPO_PUBLIC_*` variables, listed in `.env.example`;
    they're compiled into the app, so they never hold secrets. `.env` is git-ignored because
    each machine points at its own API address.
66. **Store identifiers:** `au.com.examplerestaurant.ordering` for both platforms. Android
    package names can't contain hyphens, so the domain's hyphen is dropped.
67. **Orientation `default`.** One app serves phones in portrait and kitchen tablets in landscape.

### Design

68. **The design plan was approved as proposed**; `docs/DESIGN.md` records it. No photo source
    was chosen yet, so the app is built for the no-photo case: the header is a band of the brand
    colour carrying the name, and rows without a photo have no thumbnail.
69. **Dark-mode brand values are derived, not hand-picked.** Any brand colour set in the back
    office gets accessible light and dark variants from `src/theme/brand.ts`. For the demo maroon
    that gives `#B72F41` (buttons) and `#E18793` (text) instead of the plan's `#BA2E41` and
    `#EFA3AE`. A sweep of 4,096 colours found none that misses its contrast target.
70. **Dark mode follows the device** through `@media (prefers-color-scheme: dark)`. Moe UI's
    theme used a `.dark` class, which NativeWind v5 doesn't support on native.
71. **One font family per weight** (`font-body-semibold`, not `font-semibold`), because Android
    can't select a weight of a custom font. Fonts load with `expo-font`; iOS and Android keep
    the splash screen up until they're ready, and the web renders straight away.
72. **`cn()` knows the custom text sizes.** tailwind-merge read `text-display`, `text-heading`,
    `text-item` and `text-ticket` as colours and silently dropped the size from
    `"text-display text-brand-text"`. `src/lib/utils.ts` registers them.
73. **Restyled Moe UI components:** `Text` (the approved type scale as variants), `Button`
    (48 px, 44 px minimum, no shadows, 2 px focus ring), `Input` (48 px, a 3:1 border and an
    `invalid` prop, since React Native's types have no `aria-invalid`), `Label` and `Alert`
    (Mukta, no tight tracking), `Skeleton` (holds still with reduced motion).
74. **Adding more Moe UI components later:** the CLI stops at any file we've changed (for
    example `text.tsx`, which most components depend on). Run the add with `--overwrite`, then
    restore our versions with `git checkout -- <file>`. `docs/SETUP.md` has the steps.

### API endpoints pulled forward from Phase 2

75. **`GET /restaurants/{slug}` and `OpeningHoursService`.** A shift belongs to the local date it
    opens on, and a closing time at or before the opening time runs past midnight. A
    special-hours entry replaces only its own date's shifts, so a Christmas Day closure doesn't
    cut off Christmas Eve's shift that runs past midnight. Touching or overlapping shifts merge,
    so "open until" is the end of the whole block. The next opening is searched 14 days ahead;
    special hours are listed 30 days ahead.
76. **Open and paused are separate.** `status.is_open` is about opening hours,
    `is_accepting_orders` is the pause switch, and `can_order_asap` requires both.
77. **Only active delivery zones are shown**, and delivery counts as enabled only when at least
    one zone is active.
78. **Sign-in tokens** are Sanctum personal access tokens named after the device ("iOS app",
    "Website"). They don't expire; signing out revokes the current one, and deleting the account
    revokes them all.
79. **Sign-in doesn't reveal who has an account.** A wrong password and an unknown email get
    the same message, and the password is hashed either way so response times match.
80. **Rate limits:** sign-in 5 a minute per email and IP address plus 20 a minute per IP;
    registration 10 an hour per IP. A 429 says how many seconds to wait and sends
    `Retry-After`.
81. **`DELETE /me` anonymises past orders**, clearing the name (now "Deleted customer"), phone,
    email, street address, delivery instructions, order notes and push token, and keeps the
    suburb, postcode, items and totals as the financial record. Staff and owner accounts are
    refused with a 403 (`UserPolicy`) so a restaurant can't lose its owner from the app.
82. **API 404s say only "Not found."** Laravel's default message names internal model classes.
83. **CORS is limited to the API** (`api/*`) and to the origins in `CORS_ALLOWED_ORIGINS`
    (`http://localhost:8081` locally, the website in production). No cookies or credentials, since
    the API uses bearer tokens. With a single allowed origin the header always names that
    origin, so browsers block every other site.
84. **Artisan and Composer run as the `sail` user** (`docker compose exec -u sail …`).
    `docker compose exec` defaults to root, which leaves root-owned files the host user can't edit.

### App architecture

85. **One API client** (`src/lib/api/client.ts`). It adds the token, turns every error into an
    `ApiError` with the documented message and field errors, and signs the user out on this
    device when the API rejects their token (the auth interceptor). Every response is checked
    against a Zod schema (`src/lib/api/schemas.ts`) that mirrors `docs/API.md`.
86. **Session state is split.** A small Zustand store holds only the token and whether it has
    been restored; the user's details come from TanStack Query (`GET /me`).
87. **Token storage.** iOS and Android keep the token in the Keychain or Keystore
    (`expo-secure-store`). The web keeps it in memory with a `localStorage` copy so a reload
    keeps you signed in. The trade-off is that any script running on the page could read
    `localStorage`, so an XSS bug could steal a token. The alternative, Sanctum's cookie-based
    SPA sessions, needs the website and API on one site with CSRF protection, which doesn't suit
    the spec's bearer tokens. Mitigations: the site loads no third-party scripts, React escapes
    what it renders, tokens can be revoked by signing out, and Phase 7 can add a Content
    Security Policy.
88. **Offline banner.** TanStack Query's online state drives it: browser events on the web, and
    `expo-network` on iOS and Android. `expo-network` is a small first-party Expo module added
    for the spec's offline banner.
89. **The menu screen is a placeholder until Phase 5**: the branded header, open/closed status,
    opening hours, and "Online ordering opens soon" with the restaurant's phone number. The
    account screen has sign in, create account, sign out and delete account; order history and
    saved addresses wait for their endpoints (Phases 2–3).
90. **Web bundle size.** The production web bundle is 4.6 MB. About a quarter of it is the
    whole `lucide-react-native` set (about 1,600 icons, 1.2 MB of source), because Metro
    doesn't tree-shake by default and the app uses only a handful of icons. Phase 7 will trim
    it with per-icon imports or Expo's tree shaking.

## Phase 2: Menu and pricing API

Built after Phase 4, which had already added the restaurant endpoint (open/closed status and
hours) and the account endpoints.

### Quotes

91. **A quote always answers 200 for a well-formed cart.** Problems that stop it being ordered
    (sold out, a missing choice, closed, outside the delivery area, a code that can't be used)
    come back in `errors` alongside the totals, so the cart can show both. Only a malformed cart
    (no items, a missing postcode for delivery, a silly quantity) is a 422. Phase 3's order
    creation reuses the same `PricingService` and refuses any cart with errors.
92. **Lines with problems are priced and shown, but left out of the totals.** The totals are
    then what the customer would pay for what can actually be ordered, and they change as
    problems are fixed.
93. **Minimum orders apply to the food**, before the delivery fee and before any discount,
    matching "Before the delivery fee" in the back office. A promo code's own minimum works the
    same way.
94. **Discounts come off the food only**, never the delivery fee. A percentage is rounded to the
    nearest cent; a fixed amount never exceeds the food subtotal. Options can have negative
    prices, but a line never goes below zero.
95. **A code that can't be used blocks the order** with a message saying why (unknown, not
    started, expired, used up, below its minimum), rather than being dropped silently and
    surprising the customer at the total.
96. **Pausing stops ASAP orders only.** The spec says ASAP orders need the restaurant open and
    accepting orders; scheduled orders for later are still taken while paused, and staff can
    reject any order.
97. **Option IDs aren't validated with `distinct`.** Laravel compares that rule across every
    line, so two lines couldn't both choose "Chicken". A repeated ID within one line counts once.
98. **Errors carry a stable `code`** for the app, plus a message that says how to fix the
    problem and the request field it concerns (the codes are listed in `docs/API.md`).

### Scheduling

99. **Slots are local quarter-hours inside opening hours, up to 7 days ahead.** A slot is when
    the customer wants the food: ready for pickup, or delivered. The first slot is the lead time
    after now (the restaurant's prep time for pickup, the zone's delivery time for delivery, or
    the longest zone's while the postcode isn't known), rounded up to the quarter-hour. A shift
    that runs past midnight offers times after midnight; the closing time itself isn't offered.
100. **Added `GET /restaurants/{slug}/slots`**, which isn't in the spec's list, so the cart can
    offer times without the app re-implementing the rules. The quote accepts only a time this
    endpoint offers.

### Menu and delivery

101. **The menu shows sold-out items and options, marked sold out**, so customers see what's
    usually available. Hidden items (`is_active` off), hidden categories and categories with no
    items are left out. The menu isn't cached, so a sold-out switch shows on the next request.
102. **Dietary tags and allergens come as `{value, label}` pairs**, and each option group
    carries its rule in words (`selection_rule`, "Required · choose 1"), so every app and
    restaurant shows the same wording.
103. **Overlapping delivery zones: the cheapest wins.** When a postcode isn't covered, the
    message lists the postcodes that are.
104. **Rate limits:** quotes 60 a minute per IP address (this also stops promo-code guessing,
    since codes are only checked through quotes) and delivery checks 30 a minute. The menu and
    slots aren't limited.

### Housekeeping

105. **Store UTC in date attributes.** Laravel writes a date's wall-clock time without
    converting it to the app's timezone (UTC), so a Melbourne-time value would be saved hours
    off. The back office already converts; code and tests assign UTC values.
106. **Restored the executable bit on `vendor/bin` tools**, including `sail`, which was lost
    when the project was copied from the Windows drive. `composer install` sets it again on a
    fresh checkout.

## Phase 3: Orders and payments

### Payments

107. **Stripe sits behind a `PaymentGateway` interface** (create, retrieve and cancel
    PaymentIntents, and refund), so tests use a fake. Webhook verification isn't faked: it uses
    `Stripe\Webhook::constructEvent` with a 5-minute tolerance, and the tests sign events the
    same way Stripe does.
108. **PaymentIntents use automatic payment methods**, which gives cards, Apple Pay and Google
    Pay through PaymentSheet and the Payment Element without listing them. Currency comes from
    the restaurant (AUD).
109. **Idempotency on both sides.** The app's `Idempotency-Key` is scoped to the restaurant and
    stored on the order with a SHA-256 fingerprint of the request (without the key and the
    push token, which a retry may add). The same key and request returns the original order
    (`200`), the same key with a different request is `422`, and a request whose PaymentIntent
    is still being created (a cache lock) is `409`. Stripe gets its own key per order
    (`order-{public_id}`), and refunds use `refund-{public_id}`, so no retry charges or refunds
    twice.
110. **The order row is created before the PaymentIntent.** If Stripe fails, the order stays
    unpaid without one, and a retry with the same key picks up from there. Otherwise it is
    cancelled with the other unpaid checkouts after 30 minutes. The client secret is never
    stored: a retry fetches it from Stripe.
111. **Only the webhook marks an order paid.** It is stored once per event ID (a unique index;
    a replay is acknowledged and ignored) and processed on the queue (straight away since phase
    6, see 190, with the queue for retries). Paying twice, a second
    event for the same payment, and a retried job are all no-ops. A payment whose amount doesn't
    match the order is logged as critical and not placed. A payment that arrives after its
    checkout was cancelled is refunded automatically.
112. **Refunds are queued jobs** (5 tries with backoff) rather than calls inside the request, so
    a slow Stripe never holds up the kitchen. A refund that still fails is logged as critical
    for the owner to finish in the Stripe dashboard.
113. **Expired checkouts also cancel their PaymentIntent**, so a payment sheet left open can't
    be paid later. If it's paid anyway in the gap, rule 111 refunds it.

### Lifecycle

114. **The state machine lives in `OrderService`** and is the only code that writes an order's
    status (Phase 1 kept `status` out of mass assignment for this). Each change runs in a
    transaction with the order row locked, is written to `order_status_events` with who made
    it and why, and sets its timestamp column.
115. **Allowed transitions:** unpaid → placed or cancelled; placed → accepted, rejected or
    cancelled; accepted → preparing; preparing → ready; ready → out for delivery (delivery only)
    or completed (pickup only); out for delivery → completed; any unfinished order →
    cancelled. So `accepted → ready` isn't allowed: the kitchen marks an order preparing first.
    (Phase 6's kitchen screen can send both with one tap.)
116. **Cancelling a paid order refunds it**, whoever cancels it: staff, the back office, or the
    system.
117. **A scheduled order keeps its promised time** as `estimated_ready_at` when the kitchen
    accepts it early; an ASAP order gets now plus the prep time.
118. **Order numbers are assigned when payment succeeds**, under a lock on the restaurant's
    row, so orders paid at the same moment get consecutive numbers (the unique index is the
    backstop). The business day changes at 4 am local time (`ordering.business_day_starts_at_hour`),
    so a shift that runs past midnight doesn't restart at 001 halfway through.
119. **Auto-reject counts from when the kitchen could see the order:** the time it was paid,
    or the next opening time if it was paid (for later) while closed. The reason the customer
    sees is "It wasn’t confirmed in time".
120. **Promo code uses are counted when an order is paid**, not at checkout, so abandoned
    checkouts don't use them up.

### Notifications

121. **Everything happens after the database commits**, so no listener sees a change that was
    rolled back. Push notifications, emails and refunds go through the database queue;
    broadcasts did too until phase 6 (see 189).
122. **Broadcasts carry status and times only** (plus the order number and fulfilment type) on
    both channels, the staff channel included. Screens fetch details from the API when an event
    arrives, so personal details never travel over WebSockets.
123. **The customer channel `order.{public_id}` is public**, as the spec names it: guests have no
    account to authorise with, the ULID is unguessable, and the payload has nothing personal.
    Staff channels are private and authorised at `POST /api/v1/broadcasting/auth` with the
    Sanctum token.
124. **Push notifications go to every device the customer registered**: the guest token saved on
    the order, and their account's tokens for this restaurant. Tokens Expo reports as
    `DeviceNotRegistered` are deleted. Unpaid checkouts never notify anyone. A queued push is
    skipped if the order has already moved on, so a late job never contradicts a newer one.
125. **Emails:** the confirmation when an order is placed doubles as the tax invoice (seller
    name and ABN, date, items, and the GST included). A second email, not in the spec, tells the
    customer when a paid order is rejected or cancelled and refunded, since a guest without push
    notifications would otherwise never know.
126. **Tracking links** use the restaurant's custom domain when it has one, otherwise
    `ORDERING_WEB_URL`, and carry the tracking token.

### Endpoints

127. **Tracking hides names and contact details.** `GET /orders/{public_id}` needs the tracking
    token or the owning customer, and answers 404 to anyone else. It leaves out the name, phone,
    email and street address, because tracking links get forwarded.
128. **Staff act for one restaurant at a time:** the `restaurant` slug when given, otherwise the
    only restaurant they work at (422 if they work at several). Other restaurants' orders, menus
    and switches are 403. Staff endpoints address orders by `public_id`, keeping integer IDs
    internal.
129. **The staff queue returns full details** (customer, address, items, notes) because the
    kitchen and drivers need them. The customer's view doesn't.
130. **`/me/addresses` was built here.** It was listed for Phase 2 in `docs/API.md`, but the
    spec's Phase 2 list didn't include it. Another customer's address is 404, not 403.
131. **Rate limits:** placing orders 10 a minute per IP (retries of the same order count, but
    reuse it), push-token registration 20 a minute. The webhook isn't limited; its signature is
    the protection.
132. **Back-office refunds** (an owner's "Refund" action on a paid order) cancel an unfinished
    order, which refunds it, and refund a finished one without changing its status. The reason
    is saved on the order's timeline.

### Local setup

133. **A `reverb` container** runs `reverb:start` on port 8080. Laravel publishes to
    `REVERB_HOST=reverb` inside Docker, while the apps connect to `localhost` (or `10.0.2.2`, or
    a LAN IP) with their own `EXPO_PUBLIC_REVERB_*` settings.
134. **The installer's Vite leftovers were removed** (`resources/js/echo.js` and `VITE_REVERB_*`):
    the backend has no JavaScript build. Reverb's settings were added to `.env.example`.
135. **Feature tests can't reach the internet.** Every one installs the fake payment gateway, fakes
    Expo's push API, and turns any other HTTP request into a failure.

## Phase 5: Customer app

### Libraries

136. **NetInfo instead of expo-network** for the online/offline state on iOS and Android.
    pusher-js's React Native build needs `@react-native-community/netinfo` anyway, so one
    library serves both the offline banner and the socket.
137. **The cart and recent orders are kept with AsyncStorage** (localStorage on the web), per
    restaurant. They aren't secret; SecureStore stays for the sign-in token only.
138. **pusher-js picks its own build.** Its package fields point Metro at the React Native build
    on iOS and Android and the browser build on the web, so the app imports `pusher-js` once.
139. **Stripe:** `@stripe/stripe-react-native` 0.64.0 (the version Expo SDK 57 lists) for
    PaymentSheet, and `@stripe/stripe-js` with `@stripe/react-stripe-js` for the web's Payment
    Element. All pinned.
140. **Choices are our own `ChoiceRow`, not Moe UI's radio group and checkbox** (added, then
    removed with their packages). The whole row is the tap target with the price on the right,
    which Moe UI's label can't hold. React Native Web only lets Space press buttons, so the rows
    (and the segmented control and time chips) handle Space themselves.

### Menu and cart

141. **The menu keeps sold-out dishes in place**, dimmed and labelled "Sold out", but they can't
    be opened. Sold-out options are disabled; one already in a cart line can still be unticked.
142. **Required choices aren't preselected**, so nobody gets Pork by accident. An optional
    pick-one group gets a "None" choice, since a radio button can't be cleared.
143. **Category chips stick to the top and follow the scroll.** They reach up under the status
    bar with a negative margin, so once stuck they cover it; the status bar turns dark as the
    brand band scrolls away. Jumps respect reduced motion.
144. **Item options are a native bottom sheet on iOS and Android and a dialog on the web**
    (bottom sheet under 640 px, centred above). The web dialog closes on Escape or a click
    outside, keeps Tab inside, and returns focus. The menu is the stack's first screen, so a
    shared item link still has the menu underneath.
145. **Prices in the cart store are for display only.** The cart and checkout show the server's
    quote; lines with the same dish, choices and note merge, up to 50 (the API's limit).
146. **The quote is the app's only delivery check.** Delivery isn't priced until there's a
    4-digit postcode; the quote then gives the zone, fee and minimum, or says why not. The
    `delivery-check` endpoint stays for other clients.
147. **Quote problems show where they belong:** under the line, the postcode, the time or the
    promo code; anything else in a list above "Check out". "Check out" is enabled only when the
    quote on screen is for the current cart and says the order can be placed.
148. **One pickup-or-delivery choice**, in the cart store, shown on the menu and in the cart.
    Changing it clears a chosen time. A choice the restaurant stops offering is switched.
149. **Times are shown in the restaurant's timezone**, as days (Today, Tomorrow, Fri 3 Oct)
    and quarter-hours from `/slots`. ASAP shows its estimate, or "Not available now".
150. **"Order again" adds to the cart rather than replacing it:** each dish still on the menu,
    with the options still offered, at today's prices. The cart says how many dishes couldn't
    come back.

### Checkout and payments

151. **The order is created when "Place order" is tapped**, not when checkout opens, so
    browsing checkout never leaves unpaid orders. PaymentSheet is set up after the order exists;
    the web uses Stripe's deferred-intent flow (`elements.submit()`, create, then confirm).
152. **One Idempotency-Key per version of the order.** Placing the same order again (after
    closing the sheet or a declined card) reuses the key and gets the same order and
    PaymentIntent back; any change to the order gets a new key. The push token isn't part of it.
153. **A changed total stops the payment.** If the server's amount differs from the quote on
    screen (a price or promo changed in between), the app shows the new total and asks the
    customer to place the order again, which returns the same order.
154. **The checkout form is checked synchronously** when "Place order" is tapped, so the web can
    still open Apple Pay or Google Pay within that tap. Errors clear as fields are fixed.
155. **Name, email and phone aren't asked for twice:** the payment forms hide those fields and
    send them as billing details.
156. **Stripe's forms get the design's colours as values** (they can't read CSS variables):
    flat, 8 px corners, light and dark; the chosen payment method is filled with the text
    colour, like the app's own choices, and the native sheet's Pay button uses the brand colour.
157. **Web payments confirm without leaving the page when they can** (`redirect: 'if_required'`).
    When a bank does redirect, Stripe returns to the order's tracking page, which clears the
    cart on `redirect_status=succeeded`.
158. **Stripe's return links on iOS and Android** go to Stripe's `handleURLCallback`, and
    `+native-intent.ts` stops the router navigating to them.
159. **Without a Stripe publishable key the app still runs**; checkout says payments aren't set
    up, as the backend's 503 does.
160. **Apple Pay's Merchant ID comes from `APP_APPLE_MERCHANT_ID`** (default
    `merchant.<bundle ID>`) and is used by both the config plugin and the runtime provider.
    Google Pay uses its test environment in development builds.
161. **Guest checkout, with signed-in details filled in.** Saved addresses are choices; picking
    one sets the cart's postcode, which reprices the order. A new address can be saved to the
    account; that happens after payment and never holds it up.
162. **After paying:** the order and its tracking token are remembered on the device, the order
    screen opens with the menu underneath, and the cart is emptied.

### Tracking and notifications

163. **Live updates trigger a refetch.** The public channel `order.{public_id}` carries status
    and times only; the screen then loads the order. While the socket is down it polls every
    10 seconds (every 3 while a payment is being confirmed) and stops at a final status.
164. **Notifications are opt-in, on the order screen** ("Turn on notifications"), never asked
    at launch. If the device already allows them, its token goes with the next order, with no
    extra step. On Android the "Order updates" channel is created before asking (Android 13).
165. **The web has no push.** Expo's push service doesn't reach browsers; the web relies on live
    updates and polling.
166. **Tapping a notification opens its order**, including the tap that launched the app.
167. **A banner on the menu tracks the current order**: one placed on this device in the last
    12 hours that isn't finished.
168. **Tracking links are remembered.** Opening one (from the confirmation email) stores its
    token on the device, so the order opens again from notifications or the banner.

### Backend changes

169. **Order items in the API now carry `menu_item_id` and `modifier_option_id`** (null once
    deleted), so "Order again" can rebuild the cart.
170. **Push messages name the Android channel** (`channelId: default`), the one the app creates
    as "Order updates".
171. **Stripe Link is off in the web's Payment Element.** It asks for the email again to save the
    card with Stripe, beside the checkout's own email field. Cards, Apple Pay, Google Pay and the
    methods turned on in the Stripe dashboard (Klarna and Zip in test mode) remain.

## Phase 6: Kitchen screens

### Access

172. **The kitchen is part of the same app, at `/staff`.** A layout guards the board and
    settings: anyone not signed in as this restaurant's staff or owner goes to `/staff/login`,
    which explains when a customer account is signed in instead.
173. **One sign-in per device.** Signing in to the kitchen signs the whole device in (the
    customer screens share the session). A kitchen tablet is a staff device, so that's accepted
    rather than keeping two sessions.
174. **The way in on phones and tablets** is a small "Restaurant staff: open the kitchen
    screens" link on the account screen, since apps have no address bar.
175. **Leaving the board by accident is blocked:** no iOS edge-swipe back on the kitchen screens,
    and Android's back button does nothing during a shift. "End shift" and "Sign out" are the way
    out.

### The board

176. **Columns:** New (placed), Preparing (accepted and preparing), Ready and Out for delivery.
    Out for delivery shows when the restaurant delivers or has orders out. From 900 px wide (a
    tablet in landscape) the columns sit side by side; narrower, one at a time behind chips.
177. **Accepting an order for now also starts it** (accept, then "preparing"), since the board
    has no accepted column and a busy kitchen shouldn't need a second tap. An order for later
    waits in Preparing, accepted, with "Start preparing". If the second step fails, the order is
    still accepted and its card offers "Start preparing".
178. **One-tap prep times of 10, 15, 20 and 30 minutes**, as the spec asks. Rejecting needs a
    reason the customer sees: three common ones or the kitchen's own words. Cancelling (any
    accepted order, refunded) takes an optional note.
179. **Cards show what the kitchen needs:** the ticket number large, pickup or delivery and
    when, the customer's name and phone, items with choices and notes, the delivery address and
    driver instructions, the total, and timing: "Accept within 4 min, or it's rejected
    automatically" (from the API's new `accept_by`; "Accept by tomorrow 5:10 pm" when it's more
    than an hour away), "Ready by 9:16 pm", or "Late by 3 min" in red.
180. **One clock for every card** (`useNow`, ticking every 15 seconds), so times move together
    without a timer per card.
181. **The queue stays fresh three ways:** live events reload it, it checks every minute while
    live (every 10 seconds when not), and it keeps checking in a background browser tab so
    alerts still sound there. The header says when live updates are off.

### Alerts and the shift

182. **"Start shift" opens the board**, because browsers only allow sound after a tap. That tap
    plays the alert once as a sound check, turns alerts on and keeps the screen awake. The shift
    lives in memory: a reload or restart means starting again (which the browser needs anyway),
    and signing out ends it.
183. **The alert repeats every 10 seconds** while any new order is waiting, and stops once every
    new order is accepted or rejected. New cards are outlined in Marigold until then, without
    flashing (reduced motion). Screen readers hear "New order".
184. **The chime is original**: three rising bell-like notes, played twice, generated with a
    short script (sine partials with decay), so there's no licence to track.
185. **`expo-audio` plays it** (Expo's audio module; `expo-av` is deprecated). Its config plugin
    is set for playback only: no microphone permission, no background audio. On iOS it plays
    even with the silent switch on and lowers other audio while it does.
186. **Keeping the screen on:** `expo-keep-awake` on iOS and Android; on the web, the Screen Wake
    Lock API directly, taken again whenever the tab comes back into view (browsers release it
    when the tab is hidden; `expo-keep-awake`'s web version doesn't take it back).

### Settings

187. **Pause and sold-out switches change straight away** on screen and are put back if the API
    refuses. Choices shared by several dishes (fillings, sauces) are listed once.
188. **Switches are whole-row toggles** (like the option rows): the row is the tap target, Space
    works on the web, on is Coriander.

### Backend changes

189. **Order updates are broadcast as they're saved, not queued** (`ShouldBroadcastNow`, still
    after the commit). A queued broadcast waited for a worker: up to a couple of seconds with
    the development worker, which starts PHP for each job. A broadcast failure is reported, not
    thrown, so a Reverb outage never fails an action; Reverb also gets 2–3 second timeouts.
    Screens poll as before when the socket is down.
190. **The Stripe webhook processes events straight away**, and queues them only to retry after
    a failure. A paid order now reaches the kitchen about 0.3 seconds after Stripe confirms the
    payment (it was about 2.7 seconds), within the spec's 2 seconds.
191. **`GET /staff/orders` adds `accept_by`** for new orders: when the auto-reject will happen.

### After phase 6

192. **The menu header shows the restaurant's logo** (from its settings) beside its name, on a
    small tile of the page colour so any logo shows on the brand band or a photo. The spec asks
    for the logo to be loaded at start-up; until now only the name and colour were used.
193. **`public/storage` is a relative link** (`../storage/app/public`), so it resolves both in
    the container and from WSL. `storage:link --relative` needs `symfony/filesystem`, which
    isn't worth a package for one link; SETUP shows the `ln -s` command.

### Dine in and table QR codes

194. **Dine in is a third way to order**, beside pickup and delivery (`fulfilment_type:
    dine_in`). The customer pays first, the kitchen takes it like any order, and staff bring it
    to the table: Ready leads to **Served** (completed), never out for delivery.
195. **Tables are listed in the back office** (Restaurant → Tables), so customers can only
    choose a real table, and each table can have a printed QR code. A table can be turned off
    (booked out) without deleting it. Labels are free text (12, A3, Patio 2), unique per
    restaurant; "Add several" numbers a run of tables in one go.
196. **Dine in is offered when switched on in Settings and at least one table is taking
    orders.** The restaurant API lists the tables customers can choose.
197. **A table's QR code opens `/table/{label}`** on the ordering site (`ORDERING_WEB_URL`), which
    chooses dine in at that table and shows the menu. The label is in the link, so renaming a
    table means printing its code again (the form says so). Unknown tables, and dine in turned
    off, are explained instead.
198. **QR codes are SVG**, made with `chillerlan/php-qrcode` (already installed for Filament's
    two-factor codes; now a direct dependency), with medium error correction so a scuffed card
    still scans. One per table in a dialog (with a download), or all of them on a printable
    sheet.
199. **Orders at a table are for now only**, while the restaurant is open and taking orders:
    no times for later, and no "order ahead" suggestion when closed. No delivery fee or minimum;
    promo codes apply. The ready-time estimate is the usual prep time.
200. **The table is stored on the order twice**: its ID, and its label as it was when ordered,
    so the order still says "table 12" if the table is later renamed or removed.
201. **The kitchen card puts the table first** ("Table 12", large); the customer's screens say
    "We'll bring it to table 12", "On its way to your table" and "Served". "Order again" keeps
    the same table, for another round.
202. **Contact details stay required at a table** (name, phone, email), as for any order: the
    receipt goes to the email and the restaurant can reach the customer. Signed-in customers have
    them filled in.

### Apple Pay and Google Pay

203. **The wallet button comes first, the card form stays.** On the web, Stripe's Express
    Checkout Element shows Apple Pay (Safari) or Google Pay (Chrome) above the card form when the
    browser has one ready; on iOS and Android, Stripe's native Apple Pay / Google Pay button sits
    above "Place order". Either way it's one tap and Face ID or a fingerprint, with no card
    number.
204. **The wallet opens only when the checkout form is complete** (checked within the tap), and
    the order is created after the customer approves the payment, exactly as for a card: the
    same Idempotency-Key rules, amount check and webhook.
205. **Wallets only in the express button**: Link, PayPal, Amazon Pay and Klarna stay out of it
    (Klarna and other dashboard methods remain in the card form's tabs).
206. **When there's no wallet** (no card in Wallet, an unsupported browser, or the web on
    `localhost`, where Apple Pay can't run), the button simply doesn't show and checkout is as
    before.

## Phase 7: Web, SEO and polish

### Server rendering

207. **The website is rendered on the server for each request**, with Expo Router's server
    rendering and data loaders (`web.output: 'server'`; both are experimental flags in SDK 57,
    stable from SDK 58). Search engines and link previews get the current menu, prices and
    opening hours in the HTML, and an owner's change shows up without a rebuild. Static
    rendering, the spec's fallback, would have frozen the menu at build time. The site now
    needs a server to run on: EAS Hosting provides it.
208. **The menu (`/`) and dish pages (`/item/3`) have loaders** that fetch the restaurant and
    menu from the API. The page's queries start from that data (TanStack Query `initialData`,
    stamped with the time the server fetched it), so the browser hydrates exactly what the
    server rendered and refreshes it on its usual schedule.
209. **Only the page the browser asked for uses its loader's data.** Pages opened later in the
    app keep using the query cache: calling `useLoaderData` there would fetch the loader from
    the server again on every visit (opening a dish would wait on a round trip), and the menu
    underneath a dish has no loader data of its own. iOS and Android never call loaders.
210. **Each server render gets its own query cache**, so data loaded for one visitor's page can
    never appear in another's. The browser and the apps keep their one shared cache.
211. **If the server can't reach the API, the page still renders** (with its loading state)
    and loads the data in the browser as before, with its error message and retry. The
    loader logs the problem instead of failing the page.

### Titles, share tags and search

212. **Titles, descriptions and Open Graph tags are written into the HTML by each page's
    `generateMetadata`.** Server rendering streams the page and doesn't include tags set with
    `expo-router/head`; Expo recommends `generateMetadata` for server-rendered pages. The page
    also renders the same tags with `<Head>` from `expo-router/head` (both come from one
    description of the page, `src/lib/page-meta.ts`). The server marks its tags as `<Head>`'s
    own, so after hydration `<Head>` takes them over instead of adding a second copy, and keeps
    them right as visitors move around the app. On iOS and Android, `<Head>` isn't rendered.
213. **Share links use the dish's photo, or the restaurant's cover photo or logo**, and a
    description from the restaurant's settings (or one built from its pickup and delivery
    options and suburb), cut to 160 characters.
214. **Private pages stay out of search results** (`noindex`): the cart, checkout, order status,
    account, the kitchen screens and table links, and a dish that's no longer on the menu.
    `robots.txt` keeps crawlers out of `/staff`; `/sitemap.xml` lists the menu and every dish.
215. **Canonical links, `og:url`, the sitemap and structured data use the site's public
    address** (now the brand's `webUrl`, see 234; at first `EXPO_PUBLIC_WEB_URL`), so preview
    deployments and other hostnames point search engines at the real site. Without it, pages
    have no canonical link.
216. **Structured data (JSON-LD) is in the menu page itself**: a schema.org `Restaurant`
    (address, phone, email, logo and photos, opening hours and special hours, closed days as
    00:00 to 00:00) and its `Menu` (sections; each dish with its price, availability and
    diets). Dairy free has no schema.org diet of its own; it's given as the nearest, low
    lactose.

### Installing and branding

217. **The site can be installed to a phone's home screen**: a web app manifest
    (`/manifest.webmanifest`, an API route, so the name, description and colour follow the
    restaurant's settings), icons for Android (including a maskable one) and iOS, the brand
    colour for the browser's toolbar, and a short name for under the icon (the brand's
    `shortName`, "Momo House"; see 233).
218. **New brand icons**: a momo in cream on the brand maroon, replacing Expo's placeholder for
    the app icon, Android adaptive icon (with a monochrome layer for themed icons), splash
    screen, favicon and the website's icons. Each restaurant has its own, in its brand folder
    (see 233).

### Layout and size

219. **From 1024 px wide, the cart sits beside the menu** (quantities and choices can be
    changed there; "View cart" opens the full cart for the time, delivery, promo code and
    checkout). Narrower screens keep the "View cart" bar. The panel is switched by CSS
    breakpoints, not by measuring the window, so the server's HTML is the same at every width
    and hydrates without a mismatch.
220. **The app's icons are defined in `src/components/icons.ts`**, with Lucide's shapes and the
    same component interface, instead of being imported from `lucide-react-native`, whose
    index puts all of its 1,600 icons in the web bundle. With that, and importing only the
    font weights the app uses, the web bundle went from 4.8 MB to 3.1 MB (905 KB to 728 KB
    gzipped). The next largest parts, Reanimated (NativeWind's animations need it) and Zod,
    stay.

### Accessibility pass

221. **Audited with axe-core** (WCAG 2.2 AA and best practices), in light and dark mode at
    360 px and 1280 px: the menu, a dish, the cart, checkout, account and the kitchen sign-in
    have no violations, every control is at least 44 px, keyboard focus is visible on every
    control, and nothing animates with reduced motion. What it found and what changed:
    - **Focus rings never showed on the web.** In Tailwind 4, `outline-none` also sets the
      outline-style variable that `focus-visible:outline-2` reads, so the ring had no style.
      Every control now adds `focus-visible:outline-solid`.
    - Dish photos, the cover photo and the logo are decorative (the name is beside them) and
      now say so to screen readers (`alt=""`, which expo-image takes from
      `accessibilityLabel` on the web).
    - Each screen is the page's `main` landmark, a screen's title is its `h1`, and the back
      button is announced as a button.
    - "Sign in" at checkout was a 27 px link inside a sentence; it's now a 44 px link of its
      own.
222. **Pages hydrate without mismatches**, so the browser takes over the server's HTML as it is.
    What the server can't know stays out of the first render: the category chips' side margin
    is worked out in CSS (it came from the window's width), and the dish screen's element ids
    come from the dish. React's `useId` gives different ids on the server and in the browser
    here, because Expo's server document puts `<html>` and `<body>` around the app; components
    rendered on the server use ids from their data instead.
223. **A table's link goes back to the menu underneath it** (`dismissTo('/')`) rather than
    opening a second copy of the menu on top.
224. **The menu arrives in two parts of the same response.** React sends a section larger than
    about 25 KB (Expo's setting) after a placeholder and moves it into place with a small
    inline script, before the app's code loads. Browsers, and search engines that run
    JavaScript (Google, Bing), get the whole menu straight away, and link previews read the
    tags in the `<head>`; only with JavaScript off does the menu stay hidden. This is React's
    streaming under Expo's renderer, left as it is.

## More restaurants

225. **Each restaurant is paid into its own Stripe account**, with keys its owner enters in the
    back office (Restaurant settings → Payments); the platform holds no money. Stripe Connect,
    which would let the platform take a fee per order, was considered and not chosen for now.
    The secret key and webhook signing secret are stored encrypted (Laravel's `encrypted`
    cast, so they depend on `APP_KEY`), are never shown again once saved, and never appear in
    an API response. The keys in `backend/.env` are now only the demo restaurant's, for local
    development.
226. **A restaurant takes orders only once all three keys are in**: publishable, secret, and
    webhook signing secret (without the last, payments would go through but orders would never
    reach the kitchen). Until then the API gives no publishable key, the checkout says the
    restaurant isn't taking payments online, and an order request answers `503` before any
    order is made.
227. **The back office checks the keys as they're pasted**: a publishable key starts with `pk_`,
    a secret key with `sk_` or `rk_`, both from the same mode (test or live). Keys pasted the
    wrong way round are refused with a message saying so. **Check the keys** asks Stripe for the
    account's name.
228. **One webhook endpoint per restaurant** (`/stripe/webhook/{slug}`), verified with that
    restaurant's signing secret. Owners choose their own secrets, so an event can only ever
    affect its own restaurant's orders. Events are unique per restaurant: restaurants that share
    a Stripe account each receive, and process, their own copy. The shared
    `/stripe/webhook` address is gone.
229. **The apps get the publishable key from the API** (`payments.stripe_publishable_key`),
    instead of from `EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY` in each build. The key pair lives in one
    place and can't be mismatched. On iOS and Android, Stripe's provider is always rendered and
    is set up when the key arrives, so the app doesn't restart around it.
230. **`php artisan restaurant:create` adds a restaurant** and makes someone its owner. It asks
    for each detail or takes it as an option. A new owner gets an email with a link to choose a
    password (Filament's password reset, now turned on, which also gives the sign-in page
    "Forgot password?"); an existing account is linked to the new back office. The restaurant
    starts with pickup only, no opening hours (so it shows as closed) and no Stripe keys.
231. **Each restaurant's own domain is its web address**: tracking links in emails and table QR
    codes use it (`Restaurant::webUrl()`), falling back to `ORDERING_WEB_URL`, and the API
    allows it as a CORS origin. The list of domains is cached and cleared whenever a
    restaurant is saved; `CORS_ALLOWED_ORIGINS` is now only for other addresses.
232. **Emails carry the restaurant's name**, not the platform's: Laravel's mail layout is
    overridden so its title, header and footer use the restaurant's name and website. In the
    back office, the panel shows the current restaurant's name; `APP_NAME` remains the
    platform's, on the sign-in page and owners' invitations.
233. **A brand folder per restaurant for the apps.** `app/brands/<brand>/brand.json` holds the
    app's name, short name, store identifiers, scheme, Apple merchant ID, colour, website and EAS
    project, next to its icons. `public/brands/<brand>/` holds the website's icons, because Expo
    serves one `public/` folder for every build. `BRAND` picks the folder (the demo by default)
    for `expo start`, builds and exports; on EAS it's an environment variable of the
    restaurant's own project.
234. **The restaurant and its website come only from the brand**, not from
    `EXPO_PUBLIC_RESTAURANT_SLUG` or `EXPO_PUBLIC_WEB_URL`. A value left in a local `.env` could
    otherwise point one restaurant's build at another restaurant. Where the API and Reverb are
    stays in `EXPO_PUBLIC_*` variables, the same for every restaurant.
235. **Each restaurant has its own EAS project**: its own App Store and Google Play listings,
    and its own website on EAS Hosting with its own domain. All the projects stay in one Expo
    account, whose access token the API uses for every restaurant's push notifications.
236. **Test keys are as good as live keys**, anywhere: a restaurant can take test payments on
    its website and in its apps, store builds included, until it's ready to switch. The back
    office only checks a key's prefix and that both keys are from the same mode, and says which
    mode the restaurant is in (with a test card to try). On iOS and Android, Google Pay's test
    environment follows the key (`pk_test_…`) rather than whether it's a development build.
237. **A secret that can't be decrypted counts as missing** instead of failing every page that
    reads the restaurant (a `Secret` cast in place of Laravel's `encrypted`). That happens when
    a value is written straight into the database, or `APP_KEY` changes without
    `APP_PREVIOUS_KEYS`; it's logged, the restaurant stops taking payments, and Payments asks
    for the key again. A publishable key must start with `pk_` to count.

### Apple Pay and Google Pay in each restaurant's Stripe account

238. **Wallet buttons show wherever the browser can take them** (Stripe's `always`, in place of
    `auto` from 206): Google Pay in Chrome and Edge even before the customer has saved a card,
    Apple Pay in Safari, and Apple Pay in other browsers on computers through a QR code for the
    customer's iPhone. Stripe still hides a wallet where it can't run. The card form below no
    longer offers the wallets as tabs, so a wallet never shows twice.
239. **The back office registers the restaurant's website with its Stripe account**, through
    the restaurant's own secret key. Stripe requires the domain to be registered before it
    shows a wallet, in test mode too, and an owner wouldn't know to do it. This happens when the
    keys or the custom domain change, and when the owner presses **Check with Stripe** (which
    replaces "Check the keys" from 227). `localhost`, IP addresses and `.test`, `.local` and
    `.localhost` names are skipped, because Stripe can't register them.
240. **Only the owner's click switches wallets on** in the Stripe account ("Turn on Apple Pay and
    Google Pay", with a confirmation), because it changes the account's own payment method
    settings, for everything that uses the account. Registering the domain only adds the domain,
    so it happens without asking.
241. **The checklist shows Stripe's last answer**, kept on the restaurant (`stripe_wallets`, with
    when it was checked), so the settings page never waits on Stripe. Saving other settings
    doesn't ask Stripe again. Stripe is asked after the save is committed, so the restaurant
    isn't held locked while it answers. If it doesn't answer, the owner is told and the last
    answer stays. A new secret key forgets the last answer, which may have been about another
    Stripe account.

### A restaurant's website domain

242. **A domain is saved as the bare, lower-case domain**, however it's typed or pasted
    (`https://Order.Example.com.au/menu` becomes `order.example.com.au`). Browsers send the
    website's address in lower case and the API's CORS check compares it exactly, so a domain
    saved with capitals would stop the website from reaching the API. Domains saved before this
    are lowercased where the CORS list is built. The same rule checks the back office and
    `restaurant:create`, and refuses another restaurant's domain however it's written.
243. **`restaurant:create` takes the website's domain** (`--domain`, or an optional question).
    Only a restaurant's owners can open its back office, so this is how the operator, who
    deploys the website, can give it its domain. The owner can still add or change it in
    Restaurant settings.
244. **Check with Stripe asks Stripe to check a registered domain again** when Apple Pay or
    Google Pay isn't active on it yet: for example, when it was registered before the website or
    its DNS was ready. A domain that's active is left alone.

## Square

245. **Each restaurant takes payments with its own Stripe or its own Square account**, chosen by
    its owner in the back office. Each order records the processor it was placed with, and its
    refund, expiry and webhooks follow the order rather than the restaurant, so a switch never
    strands an order. Existing orders were all Stripe.
246. **Restaurants connect Square with OAuth** ("Connect Square") to the platform's own Square
    application, instead of pasting keys as with Stripe: owners never handle credentials, there's
    one webhook subscription per environment for every restaurant, and one Apple merchant ID can
    serve every brand's app (Apple allows one active payment processing certificate per merchant
    ID, so Square's can't share Stripe's). Sandbox and production are separate applications; a
    restaurant connects in one of them, which the back office says.
247. **A Square payment is charged while the customer waits.** The app turns the card or wallet
    into a token with Square's own form, creates the order, then sends the token to
    `/orders/{id}/square-payment`, which charges it (`CreatePayment`, `autocomplete`). A
    completed payment places the order at once. Webhooks only reconcile: a payment whose answer
    was lost, a refund that failed, an account disconnected on Square's side. Square also sends
    the restaurant's in-person sales; those are ignored without being stored.
248. **One Square payment at a time per order**, under a lock that the expiry of unpaid orders
    respects too. A paid order isn't charged again (a retry answers that it's paid); a cancelled
    one isn't charged at all. Square's idempotency key is a hash of the order and the request's
    Idempotency-Key, within Square's 45 characters: a retried request is charged once, a new card
    is a new request. A declined card leaves the order waiting for another card.
249. **Switching only goes to a processor that's ready**, after a confirmation, and the first one
    set up is used straight away. When Square is lost (disconnected, revoked on Square's side, or
    its refresh token refused), the restaurant goes back to Stripe if Stripe is set up.
250. **Cards are checked with the bank through Square's buyer verification** (3-D Secure as Square
    sees fit), on the web (verification details when the card is tokenised) and in the apps
    (Square's card form with buyer verification). Apple Pay and Google Pay authenticate on the
    device.
251. **No Square PHP SDK:** a handful of endpoints, called with Laravel's HTTP client, pinned to
    Square API version 2026-09-16, faked in tests with `Http::fake`. Only requests with an
    idempotency key (payments, refunds) are retried, when Square can't be reached or has a fault
    of its own. A declined card becomes a customer-safe message (`402`); a token Square no longer
    accepts, a lost connection.
252. **Square tokens are renewed daily when they're a week old** (`square:refresh-tokens`), as
    Square recommends; they last 30 days. A refused refresh disconnects the restaurant.
253. **Square's Apple Pay verification file is on every brand's website**
    (`/.well-known/apple-developer-merchantid-domain-association`, from `app/public`), since a
    restaurant can switch to Square at any time; Stripe's domain registration doesn't use a file.
    The website's domain is registered with the restaurant's Square account when Square is
    connected or checked, and when the domain changes.
254. **In the apps, Square's Apple Pay and Google Pay buttons are the official ones drawn by
    Stripe's package** (only the button), which the app already has; Square's SDK has none.
255. **Kotlin 2.2.21 for the whole Android build:** Square's Android SDK needs it and its config
    plugin pins the Kotlin Gradle plugin, so a small local config plugin
    (`plugins/with-kotlin-version.js`) sets Expo's `android.kotlinVersion` to match, rather than
    adding a package for one property.
