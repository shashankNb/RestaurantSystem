# Direct ordering for takeaway restaurants

A restaurant's own branded ordering app and website, so customers can order directly
instead of only through delivery marketplaces. The demo restaurant is **Himalayan Momo
House**, a fictional Nepalese takeaway in Melbourne.

- **Customers** order on iOS, Android or the web: the menu with photos, options and sold-out
  dishes, pickup, delivery or dine in (from a QR code on the table), now or for later, and
  payment by card, Apple Pay or Google Pay, into the restaurant's own Stripe or Square
  account. They follow the order live, with push notifications, and can keep an account with
  saved addresses and past orders.
- **The kitchen** works from a tablet: new orders arrive with an alert, staff accept them with
  a prep time or reject them with a reason, move them along, pause online orders and mark
  dishes sold out.
- **The owner** runs the restaurant from a back office: menu, prices and photos, opening
  hours, delivery zones, tables and their QR codes, promo codes, staff and orders, with
  refunds.
- **The website** is rendered on the server, with search-friendly pages, structured data for
  search engines, link previews, and can be installed to a phone's home screen.

| Folder | What's in it |
|---|---|
| `backend/` | Laravel 13 REST API (`/api/v1`) and the Filament owner back office (`/admin`) |
| `app/` | Expo app (SDK 57): customer ordering on iOS, Android and the web, plus the kitchen screens |
| `docs/` | [Spec](docs/SPEC.md), [setup](docs/SETUP.md), [adding a restaurant](docs/ADDING_A_RESTAURANT.md), [API](docs/API.md), [design](docs/DESIGN.md) and [decisions](docs/DECISIONS.md) |

Built with Laravel, MySQL, Sanctum, Reverb, Stripe, Square and Filament on the backend, and
Expo Router, NativeWind with Moe UI, TanStack Query, Zustand and React Hook Form with Zod in
the app.

To run it locally, follow [docs/SETUP.md](docs/SETUP.md). Deployment (the API on any PHP
server, the website on EAS Hosting) is at the end of the same guide. To put another restaurant
on the platform, follow [docs/ADDING_A_RESTAURANT.md](docs/ADDING_A_RESTAURANT.md): one
command creates it, its owner sets it up and connects their own Stripe or Square account, and
it gets its own branded app and website.

## Payment webhooks

Each restaurant's own Stripe or Square account sends payment events to the restaurant's own
webhook address. Its back office shows the exact URL (Restaurant settings → Payments), and
needs the secret that comes with it.

| | Stripe | Square |
|---|---|---|
| Where | Stripe dashboard → Developers → Webhooks → Add endpoint | Square Developer Console → the restaurant's application → Webhooks → Add subscription, in the same environment (sandbox or production) as its credentials |
| URL | `https://<API host>/api/v1/stripe/webhook/<link-name>` | `https://<API host>/api/v1/square/webhook/<link-name>` |
| Events | `payment_intent.succeeded`, `payment_intent.payment_failed` | `payment.created`, `payment.updated`, `refund.updated` |
| Into the back office | The endpoint's signing secret (`whsec_…`), under Payments → Stripe | The subscription's signature key, under Payments → Square |
| Needed? | Yes: Stripe's webhook is what marks an order paid | Recommended: Square payments are confirmed while the customer waits; the webhook catches a payment whose answer was lost, and a refund that failed |

Locally, Stripe's events come through `stripe listen`
([setup](docs/SETUP.md#stripe-test-mode-and-webhooks)); Square can't reach `localhost`, so its
events only arrive on a deployed API. What each event does is in the
[API reference](docs/API.md#post-stripewebhookslug), and the owner's steps are in
[adding a restaurant](docs/ADDING_A_RESTAURANT.md#3-payments-the-restaurants-own-stripe-or-square-account),
step 3.
