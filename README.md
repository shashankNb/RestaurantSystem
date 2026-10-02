# Direct ordering for takeaway restaurants

A restaurant's own branded ordering app and website, so customers can order directly
instead of only through delivery marketplaces. The demo restaurant is **Himalayan Momo
House**, a fictional Nepalese takeaway in Melbourne.

- **Customers** order on iOS, Android or the web: the menu with photos, options and sold-out
  dishes, pickup, delivery or dine in (from a QR code on the table), now or for later, and
  payment by card, Apple Pay or Google Pay. They follow the order live, with push
  notifications, and can keep an account with saved addresses and past orders.
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

Built with Laravel, MySQL, Sanctum, Reverb, Stripe and Filament on the backend, and Expo
Router, NativeWind with Moe UI, TanStack Query, Zustand and React Hook Form with Zod in the
app.

To run it locally, follow [docs/SETUP.md](docs/SETUP.md). Deployment (the API on any PHP
server, the website on EAS Hosting) is at the end of the same guide. To put another restaurant
on the platform, follow [docs/ADDING_A_RESTAURANT.md](docs/ADDING_A_RESTAURANT.md): one
command creates it, its owner sets it up and connects their own Stripe account, and it gets
its own branded app and website.
