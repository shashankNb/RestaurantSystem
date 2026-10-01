# App

One Expo codebase (SDK 57, Expo Router) for:

- the customer ordering app on iOS and Android,
- the ordering website,
- the kitchen screens staff use on a tablet (`/staff`).

It talks to the Laravel API in `../backend`, documented in [../docs/API.md](../docs/API.md).
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
| `src/app/` | Screens (Expo Router): menu, `item/[id]`, cart, checkout, `order/[publicId]`, account; `staff/` for the kitchen |
| `src/kitchen/` | The kitchen's shift, new-order alert and keep-awake |
| `src/components/` | Shared pieces; `ui/` holds the Moe UI components, restyled to docs/DESIGN.md |
| `src/lib/api/` | The API client, response schemas (Zod) and TanStack Query hooks |
| `src/cart/`, `src/orders/` | The saved cart, and orders placed on this device |
| `src/payments/` | Stripe: PaymentSheet (`.native.tsx`) and the Payment Element (`.web.tsx`) behind one interface |
| `src/lib/realtime.ts`, `src/lib/push.ts` | Live order updates (Reverb) and push notifications |

Design decisions are recorded in [../docs/DECISIONS.md](../docs/DECISIONS.md).
