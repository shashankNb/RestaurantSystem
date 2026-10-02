# App

One Expo codebase (SDK 57, Expo Router) for:

- the customer ordering app on iOS and Android,
- the ordering website,
- the kitchen screens staff use on a tablet (`/staff`).

Each restaurant gets its own build of all of these, from its brand folder (`brands/<brand>/`,
chosen with `BRAND`; the demo restaurant's by default).

It talks to the Laravel API in `../backend`, documented in [../docs/API.md](../docs/API.md).
On the web, pages are rendered on the server for each request (Expo Router server rendering
with data loaders), so the menu is in the HTML that search engines and link previews read.
Setup, development builds and running on each platform are in
[../docs/SETUP.md](../docs/SETUP.md#expo-app). The short version, for the web:

```bash
nvm use && npm install
cp .env.example .env
npx expo start --web
npm run check   # TypeScript and ESLint
```

Where things are:

| Path | What |
|---|---|
| `src/app/` | Screens (Expo Router): menu, `item/[id]`, cart, checkout, `order/[publicId]`, account, `table/[label]`; `staff/` for the kitchen; `+html.tsx` and the web's API routes (manifest, sitemap, robots.txt) |
| `src/kitchen/` | The kitchen's shift, new-order alert and keep-awake |
| `src/components/` | Shared pieces; `ui/` holds the Moe UI components, restyled to docs/DESIGN.md |
| `src/lib/api/` | The API client, response schemas (Zod) and TanStack Query hooks |
| `src/cart/`, `src/orders/` | The saved cart, and orders placed on this device |
| `src/payments/` | Stripe: PaymentSheet (`.native.tsx`) and the Payment Element (`.web.tsx`) behind one interface |
| `src/lib/realtime.ts`, `src/lib/push.ts` | Live order updates (Reverb) and push notifications |
| `src/lib/server-storefront*.tsx`, `src/lib/api/storefront.ts` | Server rendering: the menu pages' loader data and how the page starts from it |
| `src/lib/page-meta.ts`, `src/lib/structured-data.ts` | Titles, descriptions and share tags for each page; the restaurant and menu as JSON-LD |
| `src/components/icons.ts` | The icons the app uses (Lucide's shapes, without the whole icon set) |
| `brands/<brand>/`, `public/brands/<brand>/` | Each restaurant's brand: `brand.json` (name, store identifiers, colour, website) with the app icon, splash and favicon; the website's home-screen icons. `BRAND` picks one; see [../docs/ADDING_A_RESTAURANT.md](../docs/ADDING_A_RESTAURANT.md) |

Design decisions are recorded in [../docs/DECISIONS.md](../docs/DECISIONS.md).
