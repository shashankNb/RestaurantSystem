# Setup

How to run the platform locally, and what a production server needs.

| Part | Where | Status |
|---|---|---|
| API and owner back office (Laravel, Filament) | `backend/` | Built (phases 1–4): back office, menu, pricing, orders, payments, staff and account endpoints |
| Realtime (Reverb) and Stripe webhooks | `backend/` | Built (phase 3) |
| Customer app and website (Expo) | `app/` | Built (phases 4–5): menu, cart, checkout with Stripe, live order tracking, push notifications, account |
| Kitchen screens (Expo) | `app/` | Built (phase 6): live order board with alerts, accept and reject, pause and sold-out switches |

## Prerequisites

- **Docker.** Docker Desktop, or Docker Engine inside WSL 2 on Windows.
- **Git.**
- For the Expo app: **Node.js 24 LTS** (`app/.nvmrc`; React Native 0.86 needs 20.19.4 or
  later), an **Expo account** for development builds, Android Studio for the Android emulator,
  and a Mac with Xcode for the iOS simulator.

You don't need PHP or Composer installed locally: everything PHP runs inside Sail's containers.

> **Windows tip:** projects inside the WSL filesystem (e.g. `~/code/…` in Ubuntu) run much
> faster than projects on a Windows drive (`D:\…`). If you keep the project on a Windows
> drive, follow the Windows notes below.

## Backend

### First run

From `backend/`, in a WSL, macOS or Linux terminal:

```bash
cp .env.example .env

# Install PHP dependencies with a throwaway container (no local PHP needed).
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

./vendor/bin/sail up -d          # first run builds the PHP 8.5 image: allow several minutes
./vendor/bin/sail composer setup # app key, storage link, migrations and the demo restaurant
```

`composer setup` is safe to run again: it only generates `APP_KEY` when it's empty and the
demo seeder skips a restaurant that already exists.

Tip: `alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'` saves typing.

### Windows, with the project on a Windows drive (PowerShell)

Docker shows every file on a Windows drive as owned by root, so Sail's processes must run as
root too. After copying the env file, uncomment these two lines in `.env`:

```dotenv
APP_USER=root
SUPERVISOR_PHP_USER=root
```

Then, from `backend\` in PowerShell:

```powershell
Copy-Item .env.example .env   # then uncomment the two lines above
docker run --rm -v "${PWD}:/var/www/html" -w /var/www/html laravelsail/php84-composer:latest composer install --ignore-platform-reqs
docker compose up -d
docker compose exec laravel.test composer setup
```

Anywhere this guide says `sail artisan …`, you can run
`docker compose exec laravel.test php artisan …` instead. If `composer install` stops with
"Could not delete …", Windows Defender was scanning a new file; run it again.

On WSL, macOS or Linux without the `sail` script, add `-u sail`
(`docker compose exec -u sail laravel.test php artisan …`). Plain `docker compose exec`
runs as root, and any file it creates (for example with `make:` or `config:publish`) ends up
owned by root, so you can't edit it.

### What's running

`sail up -d` starts six containers:

| Service | What it does | Address |
|---|---|---|
| `laravel.test` | API and back office | http://localhost (API under `/api/v1`, back office at `/admin`) |
| `queue` | Queue worker (`queue:listen`) for emails, push notifications, refunds and retries of failed Stripe events | — |
| `scheduler` | Laravel scheduler (`schedule:work`): cancels unpaid checkouts after 30 minutes and auto-rejects orders not accepted in time, every minute | — |
| `reverb` | Laravel Reverb, the WebSocket server for live order updates | `ws://localhost:8080` |
| `mysql` | MySQL 8.4. Database `ordering` (tests use `testing`), user `sail`, password `password` | `127.0.0.1:3307` from your machine |
| `mailpit` | Catches all outgoing email | http://localhost:8025 |

The queue worker, scheduler and Reverb start automatically; nothing else needs a terminal.
`queue:listen` reloads your code for every job. Useful commands:

```bash
sail logs -f queue scheduler      # watch jobs and scheduled tasks
sail logs -f reverb               # watch WebSocket connections
sail restart queue                # after changing .env or config
sail artisan queue:failed         # list failed jobs; sail artisan queue:retry all
```

### Demo accounts

All demo accounts use the password `password`.

| Account | Email | Can use |
|---|---|---|
| Owner | `owner@example.com` | Back office at http://localhost/admin, and the kitchen screens |
| Staff | `staff@example.com` | Kitchen screens only |
| Customer | `customer@example.com` | Customer app; has a saved Southbank (3006) address |

The demo restaurant is **Himalayan Momo House** (`himalayan-momo-house`): 5 categories,
21 items, the filling, sauce and spice level option groups, 5pm–10pm daily (11pm Friday and
Saturday), delivery to 3000, 3006 and 3008 for $6.00 with a $25.00 minimum, and the promo
code `MOMO10` (10% off orders of $30 or more).

### Web app access (CORS)

Browsers may call the API only from the origins listed in `CORS_ALLOWED_ORIGINS` in `.env`
(comma-separated). The default, `http://localhost:8081`, is the Expo web dev server. In
production, set it to the website, `https://example-restaurant.com.au`. The iOS and Android
apps aren't affected.

### Database

```bash
sail artisan migrate                  # apply new migrations
sail artisan migrate:fresh --seed     # start again from an empty database
```

### Tests and code quality

```bash
sail test                 # Pest, against the MySQL "testing" database
sail composer lint        # Pint, check only (sail composer format to fix)
sail composer analyse     # Larastan, level 7
sail composer check       # all three
```

### Realtime (Reverb)

The `reverb` container serves WebSockets on port 8080. Laravel publishes to it from inside
Docker, so `backend/.env` has `REVERB_HOST=reverb` (the service name); the apps connect from
outside with their own settings in `app/.env`:

```dotenv
EXPO_PUBLIC_REVERB_APP_KEY=   # REVERB_APP_KEY from backend/.env
EXPO_PUBLIC_REVERB_HOST=localhost   # 10.0.2.2 on the Android emulator, your LAN IP on a phone
EXPO_PUBLIC_REVERB_PORT=8080
EXPO_PUBLIC_REVERB_SCHEME=http
```

Broadcasts go through the queue, so the `queue` container must be running too. Staff channels
are authorised at `POST /api/v1/broadcasting/auth` with the app's bearer token.

After pulling this change the first time, start the new container with `sail up -d`.

### Stripe (test mode) and webhooks

1. In the Stripe dashboard, in test mode, copy the keys from Developers → API keys:
   ```dotenv
   # backend/.env
   STRIPE_KEY=pk_test_…      # publishable key
   STRIPE_SECRET=sk_test_…   # secret key: only ever here
   # app/.env
   EXPO_PUBLIC_STRIPE_PUBLISHABLE_KEY=pk_test_…
   ```
   Restart `npx expo start` after changing `app/.env`.
2. Install the [Stripe CLI](https://docs.stripe.com/stripe-cli) and sign in with `stripe login`.
3. Forward webhooks to your local API while you work:
   ```bash
   stripe listen --forward-to localhost/api/v1/stripe/webhook \
     --events payment_intent.succeeded,payment_intent.payment_failed
   ```
   It prints a signing secret (`whsec_…`). Put it in `backend/.env` as
   `STRIPE_WEBHOOK_SECRET`. It stays the same between runs on the same machine.
4. Pay with the test card `4242 4242 4242 4242`, any future expiry date, any CVC and any postcode.
   `4000 0025 0000 3155` asks for a bank check (3-D Secure) and `4000 0000 0000 9995` is declined.

Without keys, placing an order answers `503` "Payments aren’t set up yet", and the app's
checkout says payments aren't set up. Tests never call Stripe: they use a fake gateway and sign
webhooks with a test secret.

Without `stripe listen` running, payments succeed but orders stay at "Confirming your payment"
until the webhook arrives, because the webhook is what marks an order paid.

With test keys, Stripe.js adds a small "stripe" button in the corner of web pages ("Open Stripe
Developer Tools"). It comes from Stripe, not the app.

### Troubleshooting

- **A port is already in use:** change `APP_PORT`, `FORWARD_DB_PORT`,
  `FORWARD_MAILPIT_PORT` or `FORWARD_MAILPIT_DASHBOARD_PORT` in `.env`, then `sail up -d`.
- **"Permission denied" writing to `storage/` on Windows:** set `APP_USER=root` and
  `SUPERVISOR_PHP_USER=root` in `.env`, then `sail up -d` to recreate the containers.
- **You changed `compose.yaml` or the PHP version:** `sail build --no-cache && sail up -d`.
- **Uploaded photos don't show** (broken previews in the back office, photo URLs under
  `/storage/` answer 404): `public/storage` must be a link to `storage/app/public`. Copying the
  project through a Windows drive turns links into empty files. Recreate it from `backend/`:
  `rm -f public/storage && ln -s ../storage/app/public public/storage`.

## Expo app

The customer app, website and kitchen screens: Expo SDK 57 with Expo Router, from `app/`.
It runs as a **development build**, not in Expo Go, because push notifications and Apple Pay
and Google Pay need native configuration.

### First run

```bash
cd app
nvm use                 # Node 24, from .nvmrc
npm install
cp .env.example .env    # then set EXPO_PUBLIC_API_URL for where you'll run the app
```

### Web

```bash
npx expo start --web    # opens http://localhost:8081
```

The web build needs no Expo account or native tools. The backend must be running: the app
loads the restaurant, its menu and prices from the API. You can browse the menu, build a cart,
check out (with Stripe test keys, see above), and follow the order live. Sign in with a demo
account to see order history and saved addresses.

To try the whole order flow on one machine:

1. Start the backend (`sail up -d`, which includes the queue and Reverb) and
   `stripe listen …` as above.
2. Order on the web app and pay with `4242 4242 4242 4242`.
3. Accept it in the back office at `http://localhost/admin` (or with the staff API) and watch
   the order screen update without a refresh.

Stripe's Payment Element can offer Apple Pay in Safari and Google Pay in Chrome only over HTTPS
with a registered domain; on `localhost` it shows the card form.

### Android and iOS (development builds)

A development build is your own version of the app with the native modules this project uses.
Build it once, install it, then `npx expo start` serves your code to it, with fast refresh.
Rebuild only after adding a native package or changing `app.config.ts`.

1. Sign in to Expo and link the project (first time only):
   ```bash
   npm install --global eas-cli
   eas login
   eas init                # creates the EAS project; put its id in .env as EAS_PROJECT_ID
   ```
2. Build and install:
   - **Android emulator or phone:** `eas build --profile development --platform android`, then
     install the APK from the link EAS prints.
   - **iOS simulator (needs a Mac):** `eas build --profile development-simulator --platform ios`.
   - **iPhone:** `eas build --profile development --platform ios`. This needs an Apple
     Developer account; EAS walks you through registering the device.
   - **Local builds** without EAS: `npx expo run:android` (Android Studio) or
     `npx expo run:ios` (Xcode, macOS only).
3. Start the development server with `npx expo start` and open the project from the
   development build.

The app reaches the API at `EXPO_PUBLIC_API_URL`, and `localhost` means the device itself.
Use `http://10.0.2.2/api/v1` on the Android emulator, and your computer's LAN IP on a phone.
Do the same for `EXPO_PUBLIC_REVERB_HOST`. Photo URLs come from the backend's `APP_URL`, so
set that to the same address (for example `APP_URL=http://192.168.1.20`) to see photos on a
device.

### Kitchen screens

The kitchen's order board and switches, for the restaurant's staff:

- **Web:** http://localhost:8081/staff
- **App:** Account → "Restaurant staff: open the kitchen screens"

Sign in as `staff@example.com` (or the owner) with `password`, then tap **Start shift**: that
turns on the alert for new orders and keeps the screen on. Browsers only play sound after a
tap, so the board always opens on that button; after a reload, tap it again. Keep the device's
volume up.

To try it on one computer, open the kitchen in one browser window and order from the menu in
another (a private window, so the two aren't signed in as the same person). The order appears
on the board with the alert within a second of paying, and the customer's order page follows
each step the kitchen takes. Settings has the pause switch and the sold-out switches.

On a tablet in landscape (900 px wide or more) the four columns sit side by side; on a phone,
one at a time. The alert sound needs `expo-audio`, a native module: rebuild your development
build after pulling this change.

### Payments on iOS and Android

PaymentSheet takes cards with the publishable key alone. For the wallets:

- **Apple Pay** needs an Apple Merchant ID (Apple Developer → Identifiers → Merchant IDs, for
  example `merchant.au.com.examplerestaurant.ordering`), added to Stripe (Settings → Payment
  methods → Apple Pay → iOS certificate). Put it in `.env` as `APP_APPLE_MERCHANT_ID` (the
  default is `merchant.` plus the bundle ID) and rebuild: the config plugin adds it to the
  app's entitlements.
- **Google Pay** is enabled in the build and uses Google's test environment in development
  builds. Turn it on in Stripe (Settings → Payment methods) for the live app.

### Push notifications

Customers turn on notifications from an order's screen. They need:

1. An EAS project: `eas init`, and its id in `.env` as `EAS_PROJECT_ID`. Without it the app
   says this phone can't get notifications.
2. A development build on a real device. Simulators and emulators can't receive them.
3. **Android:** Firebase Cloud Messaging credentials. Create a Firebase project with the app's
   package name, download `google-services.json` into `app/` (it's git-ignored) and set
   `GOOGLE_SERVICES_JSON=./google-services.json` in `.env`. On EAS, upload the file as a file
   variable named `GOOGLE_SERVICES_JSON`. Then upload the FCM V1 service account key with
   `eas credentials`. See [Expo's FCM guide](https://docs.expo.dev/push-notifications/fcm-credentials/).
4. **iOS:** an Apple Developer account. EAS creates the push key when you build.
5. Optionally, an Expo access token (expo.dev → Access tokens) in `backend/.env` as
   `EXPO_ACCESS_TOKEN`, if you turn on enhanced push security for the project.

The web has no push notifications; the order page updates live instead.

### Typed routes

Routes are typed (`.expo/types/router.d.ts`, git-ignored). `npx expo start` regenerates the
file; after adding a screen, start or restart it before `npm run check`.

### Code quality

```bash
npm run check           # tsc --noEmit and ESLint
```

### UI components (Moe UI)

Components live in `src/components/ui` and belong to this project, so edit them freely. Add
another one with its CLI:

```bash
npm run moe-ui -- add dialog
```

Use the npm script rather than `npx`: the CLI exits without doing anything when started through
`npx` (see docs/DECISIONS.md).

Several components (`text`, `button`, `input`, `textarea`, `label`, `alert`, `skeleton`) and
`src/lib/utils.ts` are restyled for this project. When a new component depends on one of them, the CLI stops
rather than overwrite our version. Commit your work first, add with `--overwrite`, then put
our versions back:

```bash
npm run moe-ui -- add dialog --overwrite
git checkout -- src/components/ui/text.tsx src/components/ui/button.tsx \
  src/components/ui/input.tsx src/components/ui/textarea.tsx src/components/ui/label.tsx \
  src/components/ui/alert.tsx src/components/ui/skeleton.tsx src/lib/utils.ts
```

The design these components follow is in docs/DESIGN.md.

## Deployment notes

Short notes for now; the full checklist comes with phase 7.

### API server

Any server that can run PHP, MySQL and long-running processes (for example Laravel Forge
on a VPS, or Laravel Cloud) works:

- **PHP 8.5** (8.4 minimum) with the `intl`, `bcmath`, `pdo_mysql`, `mbstring`, `xml`,
  `curl`, `zip` and `fileinfo` extensions, behind Nginx with HTTPS at
  `api.example-restaurant.com.au`.
- **MySQL 8.4** (InnoDB, utf8mb4).
- **A queue worker** kept running by Supervisor or systemd:
  `php artisan queue:work --tries=3 --max-time=3600`.
- **The scheduler:** a cron entry
  `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`.
- **Reverb** as another supervised process: `php artisan reverb:start --host=127.0.0.1 --port=8080`,
  behind Nginx on its own host (for example `ws.example-restaurant.com.au`) so the apps connect
  over `wss://` on port 443. Set `REVERB_HOST`, `REVERB_PORT=443` and `REVERB_SCHEME=https` to
  that public address, and restart Reverb on each deploy (`php artisan reverb:restart`).
- **Stripe:** live keys in `STRIPE_KEY` and `STRIPE_SECRET`, and a webhook endpoint in the Stripe
  dashboard for `https://api.example-restaurant.com.au/api/v1/stripe/webhook` with the events
  `payment_intent.succeeded` and `payment_intent.payment_failed`. Its signing secret goes in
  `STRIPE_WEBHOOK_SECRET`.
- **Web app origin:** `CORS_ALLOWED_ORIGINS=https://example-restaurant.com.au` and
  `ORDERING_WEB_URL=https://example-restaurant.com.au` (tracking links in emails).
- **Uploaded images:** set `MEDIA_DISK=s3` and the `AWS_*` settings, or run
  `php artisan storage:link` if the server's disk is backed up.
- **Production `.env`:** `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://api.example-restaurant.com.au`, and real mail settings.

Deploy with:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan filament:optimize
php artisan queue:restart
```

Never run the demo seeder in production: it refuses to, because its accounts use a public
password. Create the real restaurant and its owner account with `php artisan tinker`.

### Web app

Deployed with EAS Hosting at `example-restaurant.com.au`; set up in phase 7.
