# Adding a restaurant

Every restaurant on the platform gets its own:

- **back office** on the shared server, where its owner and staff manage only their restaurant;
- **Stripe account**, so customers' payments go straight to the restaurant;
- **branded app** in the App Store and Google Play, and its own **website** on its own domain,
  all built from this codebase.

Plan on about an hour of your time, plus however long the app stores take to review the apps.

## What to ask the restaurant for

- The restaurant's name, and the owner's name and email.
- Their brand colour, and a logo or mark for the app icon (square, simple, readable when tiny).
- The web address for their ordering site (for example `order.kathmandukitchen.com.au`), and
  someone who can add DNS records for it.
- A Stripe account in the restaurant's name, with their bank account for payouts. They create
  it and enter its keys themselves, so you never handle their secret keys.
- Whose Apple and Google developer accounts publish the app: usually yours, for every restaurant.

## 1. Create the restaurant

From `backend/`:

```bash
./vendor/bin/sail artisan restaurant:create
```

It asks for:
- the restaurant's name;
- its link name (for example `kathmandu-kitchen`, which can't be changed later);
- timezone, phone, email and brand colour;
- its website's domain, if you know it already (for example `order.kathmandukitchen.com.au`;
  see step 6). Otherwise the owner adds it later;
- the owner's name and email.

Every answer can also be given as an option; `--help` lists them.

It creates the restaurant with pickup on and nothing else switched on yet. The owner gets an
email with a link to choose their password and sign in. The link lasts 60 minutes; after that
they can use "Forgot password?" on the sign-in page. If the owner already has an account (they
own another restaurant, say), they just get a link to the new back office.

## 2. The owner sets up the restaurant in the back office

Signed in at `/admin`, the owner fills in:

- **Restaurant settings:**
  - details: address, phone, ABN;
  - branding: colour, logo, header photo;
  - **Payments** (step 3);
  - ordering options: pickup, delivery, dine in, prep time.
- **Opening hours** and **Holidays & special hours.** Until there are opening hours the
  restaurant shows as closed.
- **Menu:** categories, items with photos, and options such as fillings and spice levels.
- **Delivery zones,** if they deliver.
- **Tables,** for ordering at the table. Print the QR codes after step 6, once the website's
  address is set.
- **Staff,** for the kitchen screens, and **Promo codes.**

## 3. Payments: the restaurant's own Stripe account

The owner does this in **Restaurant settings → Payments**. That section shows the webhook
address to use and says whether the restaurant can take payments yet.

1. In Stripe, **Developers → API keys**: paste the publishable key (`pk_…`) and the secret key
   (`sk_…`). The back office refuses keys pasted the wrong way round, or a mix of test and live
   keys.
2. In Stripe, **Developers → Webhooks → Add endpoint**:
   - use the webhook URL shown in Payments (`https://api…/api/v1/stripe/webhook/<link-name>`);
   - choose the events `payment_intent.succeeded` and `payment_intent.payment_failed`;
   - paste the endpoint's signing secret (`whsec_…`) into Payments.
3. Press **Check with Stripe**. Payments then says "Taking payments into your Stripe account".
   Until all three keys are in, customers can see the menu but can't check out.
4. Apple Pay and Google Pay: Payments has a checklist for them, filled in from the restaurant's
   Stripe account when the keys or the website's domain change, and whenever the owner presses
   **Check with Stripe**.
   - **Switched on in Stripe:** new Stripe accounts often have Google Pay off. **Turn on Apple
     Pay and Google Pay** switches both on once the owner confirms. It changes the Stripe
     account's own settings (Settings → Payment methods), so it only happens on that click.
   - **Website:** the back office registers the website's domain with the restaurant's Stripe
     account, once the keys and the domain (step 6) are in. Stripe can't register `localhost`,
     so a copy running locally never shows the wallets, only the card form.
   - **iOS app:** Settings → Payment methods → Apple Pay → add an iOS certificate for the app's
     Apple merchant ID (the `appleMerchantId` in step 4), created in the Apple Developer
     account that publishes the app.
   - **Android app:** nothing more, once Google Pay is on.

Test keys (`pk_test_…`, `sk_test_…`, with a test-mode webhook) work everywhere: the website,
and the apps, store builds included (Google Pay then uses Google's test environment). They take
test payments with Stripe's test cards, such as `4242 4242 4242 4242`, and Payments says it's
in test mode. Paste in the live keys (and the live webhook's signing secret) when the restaurant
is ready to take real payments.

## 4. The app and website: a brand folder

Each restaurant's app and website are built from its brand folder. Copy the demo's two folders
and rename them to the restaurant's link name:

```
app/brands/himalayan-momo-house/          → app/brands/kathmandu-kitchen/
app/public/brands/himalayan-momo-house/   → app/public/brands/kathmandu-kitchen/
```

Then edit `brand.json`:

| Field | What it is | Example |
|---|---|---|
| `name` | The app's name, in the stores and under its icon | Kathmandu Kitchen |
| `shortName` | Under the icon on a phone's home screen, 12 characters at most | Kathmandu |
| `restaurantSlug` | The restaurant's link name from step 1 | kathmandu-kitchen |
| `appSlug` | The app's name on expo.dev, usually the same | kathmandu-kitchen |
| `scheme` | Deep links into the app; letters only | kathmandukitchen |
| `bundleId` | App Store and Google Play identifier, never changed once published | au.com.kathmandukitchen.ordering |
| `appleMerchantId` | Apple Pay merchant ID (see step 3) | merchant.au.com.kathmandukitchen.ordering |
| `brandColor` | The same colour as in the back office | #1F5A7A |
| `webUrl` | The website's address from step 6 | https://order.kathmandukitchen.com.au |
| `easProjectId` | Added in step 5 | |

And replace the icons, keeping their names and sizes:

| File | Size | What it is |
|---|---|---|
| `brands/<slug>/icon.png` | 1024 × 1024 | The app icon, filling the whole square, with no transparency |
| `brands/<slug>/android-icon-foreground.png` | 512 × 512 | The mark on a transparent background, inside the middle two-thirds |
| `brands/<slug>/android-icon-background.png` | 512 × 512 | The brand colour |
| `brands/<slug>/android-icon-monochrome.png` | 432 × 432 | The mark in white on transparent, for themed icons |
| `brands/<slug>/splash-icon.png` | 512 × 512 | Shown while the app opens |
| `brands/<slug>/favicon.png` | 48 × 48 | The browser tab's icon |
| `public/brands/<slug>/icon-192.png`, `icon-512.png` | 192, 512 | Home-screen icons for the website |
| `public/brands/<slug>/icon-maskable-512.png` | 512 × 512 | The same, with the mark inside the middle 80% (Android crops it) |
| `public/brands/<slug>/apple-touch-icon.png` | 180 × 180 | The website on an iPhone's home screen, filling the square |

To try it locally, from `app/`: `BRAND=kathmandu-kitchen npx expo start --web --clear`. Use
`--clear` whenever you switch brands, because Expo caches the app's configuration.

## 5. EAS: the restaurant's project, builds and store listings

Each restaurant is its own project on EAS, the Expo account that builds the apps and hosts
the websites.

1. From `app/`: `BRAND=kathmandu-kitchen eas init`. Put the project's id in `brand.json` as
   `easProjectId`.
2. Add the project's environment variables, for its production, preview and development
   environments:
   - `BRAND=kathmandu-kitchen`;
   - `EXPO_PUBLIC_API_URL`;
   - the `EXPO_PUBLIC_REVERB_*` settings (see [SETUP.md](SETUP.md#web-app)).

   For example:
   ```bash
   eas env:create --name BRAND --value kathmandu-kitchen --environment production --environment preview --environment development
   ```
3. Push notifications:
   - **Android:** a Firebase project for the app's `bundleId`. Upload its
     `google-services.json` as a file variable named `GOOGLE_SERVICES_JSON`, and the FCM key with
     `eas credentials` ([Expo's guide](https://docs.expo.dev/push-notifications/fcm-credentials/)).
   - **iOS:** EAS creates the push key on the first build.
   - The API sends every restaurant's notifications with one `EXPO_ACCESS_TOKEN`, so keep every
     restaurant's project in the same Expo account (or organisation).
4. Build and submit:
   ```bash
   BRAND=kathmandu-kitchen eas build --profile production --platform all
   BRAND=kathmandu-kitchen eas submit --platform ios
   BRAND=kathmandu-kitchen eas submit --platform android
   ```
   Each app needs its own store listing: screenshots, description, privacy policy, and a
   support contact.

## 6. The website and its domain

The website's address is set in two places, and they must match:

| Where | What uses it |
|---|---|
| `webUrl` in the brand's `brand.json` (step 4) | The website itself: its canonical links, link previews, `sitemap.xml` and `robots.txt` |
| The restaurant's domain (step 1's question, or the owner's **Restaurant settings → Details**) | The API: it accepts requests from that website, and table QR codes, links in emails, and Apple Pay and Google Pay use it |

Until the restaurant has a domain, the API uses `ORDERING_WEB_URL` from `backend/.env` instead.

From `app/`:

```bash
BRAND=kathmandu-kitchen npx expo export --platform web
BRAND=kathmandu-kitchen eas deploy --environment production          # a preview address, to check
BRAND=kathmandu-kitchen eas deploy --environment production --prod   # the live site
```

1. Give the site its domain in the EAS dashboard (the project → Hosting → Custom domain). It
   lists the DNS records to create with whoever hosts the domain's DNS:
   - a TXT record that proves you own the domain;
   - a CNAME for its HTTPS certificate (`_acme-challenge.<domain>`), which EAS renews;
   - a CNAME to `origin.expo.app` for a subdomain such as `order.kathmandukitchen.com.au`, or
     an A record for a bare domain such as `kathmandukitchen.com.au`.

   Custom domains are a paid EAS feature, one per project. On the free plan the site stays at
   `https://<name>.expo.app`, which works as the restaurant's domain in exactly the same way.
   `www.kathmandukitchen.com.au` and `kathmandukitchen.com.au` count as different domains, so
   use one.
2. If the domain wasn't given in step 1, the owner enters it in **Restaurant settings →
   Details**. Pasting the site's whole address is fine: it's saved as the bare domain. Print the
   table QR codes now.
3. Once the restaurant has both its Stripe keys and its domain, the domain is registered with
   its Stripe account for Apple Pay and Google Pay (step 3). Check that the checklist in
   Payments says it's ready. If it isn't, **Check with Stripe** asks Stripe to check it again.
4. Submit `https://<domain>/sitemap.xml` in Google Search Console.

## 7. Place a test order

With test keys in Payments (or live keys and a small order you then refund):

- [ ] The menu, photos and opening hours show on the website and in the app.
- [ ] An order paid with `4242 4242 4242 4242` reaches the kitchen screen within a few seconds,
      with its alert.
- [ ] At checkout on the website, Chrome shows a Google Pay button and Safari an Apple Pay one.
- [ ] Accepting it updates the customer's order screen, and a push notification arrives on a phone.
- [ ] The receipt email shows the restaurant's name, and its link opens the restaurant's site.
- [ ] A refund from the back office goes back to the card, in the restaurant's Stripe account.

## Good to know

- **Platform name.** Set `APP_NAME` and `MAIL_FROM_NAME` in `backend/.env` to the platform's own
  name: it's on the back office's sign-in page and on owners' invitations. Customers' emails,
  and the back office once someone is signed in, carry each restaurant's own name.
- **`APP_KEY`.** Restaurants' Stripe secret keys are stored encrypted with `APP_KEY`. Keep it
  safe. To change it, list the old key in `APP_PREVIOUS_KEYS`, or every owner has to enter
  their keys again.
- **Enter Stripe keys in the back office**, not straight into the database: secrets are stored
  encrypted, and a value that isn't counts as missing, so the restaurant stops taking payments
  until it's entered again.
- **Link names are permanent.** The website's links, the table QR codes and the Stripe webhook
  address all contain the restaurant's link name.
- **Restaurants sharing a Stripe account** (two locations of one business): each restaurant adds
  its own webhook endpoint in that account and enters the same keys.
- **More owners or staff:** the owner adds them under **Staff** in the back office.
