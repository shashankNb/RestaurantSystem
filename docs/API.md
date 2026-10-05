# API

REST and JSON, served from `https://api.example-restaurant.com.au/api/v1`
(`http://localhost/api/v1` locally). Endpoints are documented here as they are built. The
app's response types (`app/src/lib/api/schemas.ts`) follow this file.

## Conventions

### Requests

- Send `Accept: application/json` on every request, and `Content-Type: application/json`
  with a body. Responses are JSON even without the `Accept` header.
- Signed-in requests carry a Sanctum token: `Authorization: Bearer <token>`.
- Money is always integer cents, in the restaurant's currency (AUD), GST included.
- Timestamps are ISO 8601 in UTC (`2026-10-02T08:15:00Z`). Restaurant-local times, such as
  opening hours, are `HH:MM` strings in the restaurant's timezone (`Australia/Melbourne`).
  A closing time at or before the opening time means the shift ends after midnight.
- Restaurants are addressed by slug, e.g. `himalayan-momo-house`.
- Payloads are wrapped in `data`.
- Browsers may call the API only from the origins in `CORS_ALLOWED_ORIGINS` (the website).
  Native apps don't send an origin and aren't affected.

### Errors

Every error has the same shape: a `message`, plus field-level `errors` for validation
failures (HTTP 422).

```json
{
    "message": "Choose a filling for Steamed momo.",
    "errors": {
        "items.0.modifier_option_ids": ["Choose a filling for Steamed momo."]
    }
}
```

| Status | Meaning |
|---|---|
| 401 | Missing or invalid token: `{"message": "Unauthenticated."}` |
| 403 | Signed in, but not allowed (e.g. staff of another restaurant) |
| 404 | Not found, or not visible to you: `{"message": "Not found."}` |
| 409 | Conflict: a request with the same `Idempotency-Key` is still being processed |
| 422 | Validation failed; see `errors`. Also returned when an `Idempotency-Key` is reused with a different request |
| 429 | Too many requests: `{"message": "Too many attempts. Try again in 42 seconds."}`, with a `Retry-After` header |

## Endpoints

| Method and path | Access | Status |
|---|---|---|
| [`GET /restaurants/{slug}`](#get-restaurantsslug) | Public | Built (phase 4) |
| [`GET /restaurants/{slug}/menu`](#get-restaurantsslugmenu) | Public | Built (phase 2) |
| [`GET /restaurants/{slug}/slots`](#get-restaurantsslugslots) | Public | Built (phase 2) |
| [`POST /restaurants/{slug}/delivery-check`](#post-restaurantsslugdelivery-check) | Public, 30 a minute | Built (phase 2) |
| [`POST /restaurants/{slug}/orders/quote`](#post-restaurantsslugordersquote) | Public, 60 a minute | Built (phase 2) |
| [`POST /restaurants/{slug}/orders`](#post-restaurantsslugorders) | Public, `Idempotency-Key` header, 10 a minute | Built (phase 3) |
| [`GET /orders/{public_id}?token=…`](#get-orderspublic_idtoken) | Tracking token, or the customer | Built (phase 3) |
| [`POST /orders/{public_id}/square-payment`](#post-orderspublic_idsquare-payment) | Tracking token, `Idempotency-Key` header, 10 a minute | Built (Square) |
| [`POST /stripe/webhook/{slug}`](#post-stripewebhookslug) | Stripe (signature verified) | Built (phase 3; one per restaurant since phase 7) |
| [`POST /square/webhook/{slug}`](#post-squarewebhookslug) | Square (signature verified) | Built (Square) |
| [`POST /auth/register`](#post-authregister) | Public | Built (phase 4) |
| [`POST /auth/login`](#post-authlogin) | Public | Built (phase 4) |
| [`POST /auth/logout`](#post-authlogout) | Signed in | Built (phase 4) |
| [`GET /me`](#get-me) | Signed in | Built (phase 4) |
| [`DELETE /me`](#delete-me) | Signed-in customer | Built (phase 4) |
| [`GET` `POST` `PATCH` `DELETE /me/addresses`](#me-addresses) | Signed in | Built (phase 3) |
| [`GET /me/orders`](#get-meorders) | Signed in | Built (phase 3) |
| [`POST /push-tokens`](#post-push-tokens) | Signed in, or a guest with a tracking token | Built (phase 3) |
| [`GET /staff/orders?status=`](#get-stafforders) | Staff or owner | Built (phase 3) |
| [`POST /staff/orders/{public_id}/accept`, `/reject`, `/status`](#post-stafforderspublic_idaccept) | Staff or owner | Built (phase 3) |
| [`PATCH /staff/restaurant`](#patch-staffrestaurant) | Staff or owner | Built (phase 3) |
| [`PATCH /staff/menu-items/{id}`, `PATCH /staff/modifier-options/{id}`](#patch-staffmenu-itemsid) | Staff or owner | Built (phase 3) |

Realtime updates over Reverb are described under [Realtime](#realtime).

The health check `GET /up` (outside `/api/v1`) returns 200 when the app is running.

## Restaurant

### `GET /restaurants/{slug}`

Everything the app needs at start-up: branding, contact details, whether the restaurant can
take orders now, and how customers can get their food.

```http
GET /api/v1/restaurants/himalayan-momo-house
Accept: application/json
```

`200 OK`

```json
{
    "data": {
        "id": 1,
        "slug": "himalayan-momo-house",
        "name": "Himalayan Momo House",
        "description": "Hand-folded momos, chow mein and thukpa from a family kitchen in Melbourne. Order direct for pickup or delivery.",
        "phone": "03 5550 0142",
        "email": "hello@example-restaurant.com.au",
        "address": {
            "line1": "Shop 3, 210 Little Lonsdale Street",
            "line2": null,
            "suburb": "Melbourne",
            "state": "VIC",
            "postcode": "3000",
            "country": "AU"
        },
        "abn": "12 345 678 901",
        "timezone": "Australia/Melbourne",
        "currency": "AUD",
        "brand_color": "#7A1F2B",
        "logo_url": null,
        "cover_image_url": null,
        "status": {
            "is_open": true,
            "is_accepting_orders": true,
            "can_order_asap": true,
            "closes_at": "2026-09-30T12:00:00Z",
            "next_opening_at": null
        },
        "fulfilment": {
            "pickup": { "enabled": true, "prep_minutes": 20 },
            "delivery": {
                "enabled": true,
                "zones": [
                    {
                        "name": "Inner Melbourne",
                        "postcodes": ["3000", "3006", "3008"],
                        "fee_cents": 600,
                        "min_order_cents": 2500,
                        "estimated_minutes": 45
                    }
                ]
            },
            "dine_in": { "enabled": true, "tables": ["1", "2", "3", "12", "Patio 1"] }
        },
        "opening_hours": [
            { "day_of_week": 1, "opens_at": "17:00", "closes_at": "22:00" },
            { "day_of_week": 2, "opens_at": "17:00", "closes_at": "22:00" }
        ],
        "special_hours": [
            { "date": "2026-10-20", "is_closed": false, "opens_at": "12:00", "closes_at": "15:00", "note": "Lunch only" }
        ],
        "payments": { "processor": "stripe", "stripe_publishable_key": "pk_live_51…", "square": null }
    }
}
```

- `status.is_open`: inside opening hours right now, in the restaurant's timezone. A shift
  that runs past midnight counts for the evening it started, and special hours replace a
  date's regular hours. Shifts that touch or overlap are merged.
- `status.is_accepting_orders`: false while staff have paused ordering.
- `status.can_order_asap`: open *and* accepting orders. ASAP orders need this.
- `status.closes_at`: when the current opening ends (null while closed).
  `status.next_opening_at`: when it next opens, looking up to 14 days ahead (null while open,
  or if nothing is scheduled).
- `fulfilment.delivery.zones`: active zones only. Delivery is `enabled` only when the owner
  has it switched on and at least one zone is active.
- `opening_hours`: one entry per weekly shift, Monday first. `day_of_week` is 0 for Sunday to
  6 for Saturday. A day can have several shifts.
- `special_hours`: holidays and one-off changes in the next 30 days. `is_closed` is true
  for a closure, with null times.
- `payments.processor`: how customers pay, `stripe` or `square`, as the owner chose in the back
  office.
- `payments.stripe_publishable_key`: with Stripe, the restaurant's own Stripe account, for the
  apps' payment forms. Null until its owner has entered all its keys in the back office.
- `payments.square`: with Square, `{"application_id": "sq0idp-…", "location_id": "L…",
  "environment": "production"}`: the restaurant's own Square application (its ID is public),
  its Square location, and `sandbox` or `production` (from the application ID). Null until its
  owner has entered its credentials and chosen the location.
- Until the processor in use is set up, placing an order answers `503`.

`404` `{"message": "Not found."}` for an unknown slug.

## Menu and ordering

### `GET /restaurants/{slug}/menu`

The whole menu: active categories → items → option groups → options, in menu order.
Sold-out items and options stay on the menu with `is_available: false`, so customers can
see them. Hidden items and categories, and categories with nothing on them, are left out.
Nothing is cached: a sold-out switch shows on the next request.

```json
{
    "data": {
        "categories": [
            {
                "id": 1,
                "name": "Momos",
                "description": "Ten hand-folded dumplings per serve, made fresh every afternoon.",
                "items": [
                    {
                        "id": 1,
                        "name": "Steamed momo",
                        "description": "Juicy dumplings steamed to order and served with tomato achar.",
                        "price_cents": 1790,
                        "image_url": null,
                        "is_available": true,
                        "dietary_tags": [],
                        "allergens": [
                            { "value": "gluten", "label": "Gluten" },
                            { "value": "wheat", "label": "Wheat" },
                            { "value": "soy", "label": "Soy" }
                        ],
                        "modifier_groups": [
                            {
                                "id": 1,
                                "name": "Choose filling",
                                "min_select": 1,
                                "max_select": 1,
                                "selection_rule": "Required · choose 1",
                                "options": [
                                    { "id": 1, "name": "Chicken", "price_delta_cents": 0, "is_available": true },
                                    { "id": 2, "name": "Pork", "price_delta_cents": 100, "is_available": true },
                                    { "id": 3, "name": "Vegetable", "price_delta_cents": 0, "is_available": true }
                                ]
                            }
                        ]
                    }
                ]
            }
        ]
    }
}
```

- `price_cents` is the item alone; each chosen option adds its `price_delta_cents`, which can
  be negative.
- A group is required when `min_select` is above 0. `selection_rule` states the rule in words,
  for showing next to the group's name.
- `dietary_tags` values: `vegetarian`, `vegan`, `gluten_free`, `dairy_free`, `halal`.
  `allergens` values: `gluten`, `wheat`, `egg`, `milk`, `peanut`, `tree_nuts`, `sesame`, `soy`,
  `fish`, `crustacea`, `mollusc`, `lupin`.

### `GET /restaurants/{slug}/slots`

When the customer can have their order: as soon as possible, or one of the times a cart can
offer for later. Query parameters: `fulfilment_type` (`pickup`, `delivery` or `dine_in`,
required) and, for delivery, `postcode` (optional; the delivery time depends on the zone).
Dine in is for now only, so its `slots` is always empty.

```http
GET /api/v1/restaurants/himalayan-momo-house/slots?fulfilment_type=pickup
```

```json
{
    "data": {
        "fulfilment_type": "pickup",
        "asap": { "available": true, "estimated_minutes": 20 },
        "slots": ["2026-10-05T07:30:00Z", "2026-10-05T07:45:00Z", "2026-10-05T08:00:00Z"]
    }
}
```

- `asap.available`: open, taking orders, and offering this fulfilment type.
  `asap.estimated_minutes` is the preparation time for pickup, or the zone's delivery time
  (the longest zone's while the postcode isn't known).
- `slots`: every local quarter-hour while the restaurant is open, from
  `estimated_minutes` after now until 7 days ahead. A time is when the customer wants the food
  (ready for pickup, or delivered). Scheduled orders are allowed while ordering is paused; only
  ASAP orders stop.
- A fulfilment type that's switched off gets no slots and no ASAP.

### `POST /restaurants/{slug}/delivery-check`

Whether the restaurant delivers to a postcode, and on what terms.

```http
POST /api/v1/restaurants/himalayan-momo-house/delivery-check
Content-Type: application/json

{ "postcode": "3006" }
```

```json
{
    "data": {
        "deliverable": true,
        "zone": {
            "name": "Inner Melbourne",
            "postcodes": ["3000", "3006", "3008"],
            "fee_cents": 600,
            "min_order_cents": 2500,
            "estimated_minutes": 45
        },
        "message": null
    }
}
```

When it can't deliver, `deliverable` is false, `zone` is null, and `message` says why and what
to do instead: "We don’t deliver to 3121. We deliver to 3000, 3006 and 3008. Choose pickup,
or use an address in one of those postcodes." If zones overlap, the cheapest wins. `422`
unless the postcode is 4 digits.

### `POST /restaurants/{slug}/orders/quote`

Prices a cart and says whether it can be ordered as it is. The cart carries only IDs,
quantities and choices; any prices or totals in the request are ignored, and every amount is
worked out from the menu.

```http
POST /api/v1/restaurants/himalayan-momo-house/orders/quote
Content-Type: application/json

{
    "fulfilment_type": "delivery",
    "postcode": "3006",
    "scheduled_for": null,
    "promo_code": "momo10",
    "items": [
        { "menu_item_id": 1, "quantity": 2, "modifier_option_ids": [2, 4, 10], "notes": "Extra achar" },
        { "menu_item_id": 1, "quantity": 1, "modifier_option_ids": [1] }
    ]
}
```

| Field | Rules |
|---|---|
| `fulfilment_type` | Required: `pickup`, `delivery` or `dine_in` (at one of the restaurant's tables) |
| `postcode` | Required for delivery; 4 digits |
| `table` | Required for dine in: one of `fulfilment.dine_in.tables`, any case |
| `scheduled_for` | Null for as soon as possible, or an ISO 8601 time from `/slots` |
| `promo_code` | Optional; any case |
| `items` | 1 to 30 lines |
| `items.*.menu_item_id` | Required |
| `items.*.quantity` | 1 to 50 |
| `items.*.modifier_option_ids` | The chosen options; a repeated ID counts once |
| `items.*.notes` | Optional, up to 200 characters |

`200 OK` for any well-formed cart:

```json
{
    "data": {
        "can_place_order": false,
        "lines": [
            {
                "menu_item_id": 1,
                "name": "Steamed momo",
                "quantity": 2,
                "unit_price_cents": 1990,
                "line_total_cents": 3980,
                "modifiers": [
                    { "id": 2, "group": "Choose filling", "name": "Pork", "price_delta_cents": 100 },
                    { "id": 4, "group": "Sauce", "name": "Tomato achar", "price_delta_cents": 100 },
                    { "id": 10, "group": "Spice level", "name": "Hot", "price_delta_cents": 0 }
                ],
                "notes": "Extra achar",
                "errors": []
            },
            {
                "menu_item_id": 1,
                "name": "Steamed momo",
                "quantity": 1,
                "unit_price_cents": 1790,
                "line_total_cents": 1790,
                "modifiers": [
                    { "id": 1, "group": "Choose filling", "name": "Chicken", "price_delta_cents": 0 }
                ],
                "notes": null,
                "errors": ["Make a choice under “Spice level” for Steamed momo."]
            }
        ],
        "subtotal_cents": 3980,
        "delivery_fee_cents": 600,
        "discount_cents": 398,
        "total_cents": 4182,
        "gst_cents": 380,
        "promo_code": { "code": "MOMO10", "description": "10% off" },
        "fulfilment": {
            "type": "delivery",
            "estimated_minutes": 45,
            "delivery_zone": {
                "name": "Inner Melbourne",
                "postcodes": ["3000", "3006", "3008"],
                "fee_cents": 600,
                "min_order_cents": 2500,
                "estimated_minutes": 45
            },
            "table": null
        },
        "scheduled_for": null,
        "errors": [
            {
                "code": "too_few_options",
                "message": "Make a choice under “Spice level” for Steamed momo.",
                "field": "items.1.modifier_option_ids"
            }
        ]
    }
}
```

How the totals are worked out:

- A line costs (item price + chosen option prices) × quantity, never less than zero. Lines
  with a problem are priced and shown, but left out of the totals.
- `subtotal_cents`: the food, from lines without problems.
- `delivery_fee_cents`: the zone's fee for delivery; 0 for pickup.
- `discount_cents`: the promo code's percentage of the subtotal (rounded to the nearest cent),
  or its fixed amount, never more than the subtotal. Delivery isn't discounted.
- `total_cents` = subtotal − discount + delivery fee.
- `gst_cents` = total ÷ 11, to the nearest cent (menu prices include GST).

`can_place_order` is true when `errors` is empty. Each error has a `code`, a `message` that
says how to fix it, and the `field` it concerns. Line errors also appear in that line's
`errors`, for showing inline.

| Code | Field | When |
|---|---|---|
| `item_not_found` | `items.N.menu_item_id` | The item is no longer on the menu (or isn't this restaurant's) |
| `item_sold_out` | `items.N.menu_item_id` | The item is switched to sold out |
| `option_not_found` | `items.N.modifier_option_ids` | A chosen option isn't one of this item's options any more |
| `option_sold_out` | `items.N.modifier_option_ids` | A chosen option is sold out |
| `too_few_options` | `items.N.modifier_option_ids` | Fewer choices in a group than its `min_select` |
| `too_many_options` | `items.N.modifier_option_ids` | More choices in a group than its `max_select` |
| `fulfilment_unavailable` | `fulfilment_type` | Pickup is switched off, or dine in is off or has no tables taking orders |
| `unknown_table` | `table` | Dine in at a table the restaurant doesn't have, or has turned off |
| `not_deliverable` | `postcode` | Delivery is off, or the postcode isn't in an active zone |
| `below_minimum` | `items` | The food subtotal is under the zone's minimum (before any discount) |
| `closed` | `scheduled_for` | ASAP while outside opening hours; the message says when it next opens |
| `paused` | `scheduled_for` | ASAP while ordering is paused |
| `invalid_time` | `scheduled_for` | A scheduled time that `/slots` doesn't offer, or any time for dine in (it's for now) |
| `promo_invalid` | `promo_code` | Unknown, switched off, not started, expired, used up, or below the code's minimum order |

`422` (the standard validation error) for a malformed cart: no items, a missing or bad
postcode for delivery, a quantity outside 1–50, and so on.

## Orders and payments

An order starts as `pending_payment`, paid with the restaurant's processor at that moment (the
order keeps it, for its refund too):

- **Stripe:** the order comes with a PaymentIntent for the server's total. The app completes the
  payment with the client secret (PaymentSheet on iOS and Android, the Payment Element on the
  web), and Stripe's webhook marks the order paid.
- **Square:** the app gets a token from Square's payment form (card, Apple Pay or Google Pay)
  and sends it to [`/orders/{public_id}/square-payment`](#post-orderspublic_idsquare-payment),
  which charges it while the customer waits.

Once paid, the order goes to the kitchen as `placed`, with its daily number. The lifecycle:

```
pending_payment → placed → accepted → preparing → ready → (out_for_delivery) → completed
                    ↘ rejected                      (any unfinished state) ↘ cancelled
```

Delivery orders go out for delivery before they're completed; pickup orders never do. Rejecting
an order, or cancelling a paid one, refunds it in full. An unpaid order is cancelled after 30
minutes, and a placed order not accepted within the restaurant's `auto_reject_minutes` is
rejected and refunded (the clock starts at opening time for orders placed while closed).

### `POST /restaurants/{slug}/orders`

Takes the same cart as [the quote](#post-restaurantsslugordersquote), plus who it's for.

```http
POST /api/v1/restaurants/himalayan-momo-house/orders
Content-Type: application/json
Idempotency-Key: 3f6c1e9a-2b7d-4c8e-9f10-5a6b7c8d9e0f
Authorization: Bearer 3|kD8sX…          (optional: links the order to the account)

{
    "fulfilment_type": "delivery",
    "postcode": "3006",
    "scheduled_for": null,
    "promo_code": "MOMO10",
    "items": [
        { "menu_item_id": 1, "quantity": 2, "modifier_option_ids": [2, 4, 10], "notes": "Extra achar" }
    ],
    "customer": { "name": "Sam Taylor", "phone": "0491 570 110", "email": "sam@example.com" },
    "delivery": {
        "line1": "12 Southbank Boulevard",
        "line2": "Apartment 1204",
        "suburb": "Southbank",
        "state": "VIC",
        "instructions": "Buzz 1204"
    },
    "notes": "Ring when you’re here",
    "push_token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]"
}
```

| Field | Rules |
|---|---|
| `Idempotency-Key` header | Required, 8–100 characters. A new random value (a UUID) for each order, repeated unchanged on retries |
| `customer.name`, `customer.phone`, `customer.email` | Required. The phone needs at least 8 digits |
| `delivery.line1`, `delivery.suburb` | Required for delivery; `line2`, `state` and `instructions` are optional |
| `notes` | Optional, up to 500 characters |
| `push_token` | Optional Expo push token, so a guest gets notifications about this order |

`201 Created`:

```json
{
    "data": {
        "order": { "public_id": "01k6b2x0v8r5c9m3t7w4y1z6qe", "order_number": null, "status": "pending_payment", "…": "as in GET /orders/{public_id}" },
        "tracking_token": "Gs3kq9…",
        "payment": {
            "processor": "stripe",
            "payment_intent_id": "pi_3Q…",
            "client_secret": "pi_3Q…_secret_…",
            "status": "requires_payment_method",
            "amount_cents": 4182,
            "currency": "aud"
        }
    }
}
```

With Square, `payment` is just `{"processor": "square", "amount_cents": 4182, "currency": "aud"}`:
pay it next with [`/orders/{public_id}/square-payment`](#post-orderspublic_idsquare-payment).

Keep `tracking_token`: it's how a guest follows the order (and pays it, with Square). Retries
are safe:

- **The same key and the same request** returns the original order and the same PaymentIntent,
  with `200 OK` instead of `201`. Nothing new is created.
- **The same key with a different request:** `422` "This Idempotency-Key was already used for a
  different order. Send a new key for a new order."
- **While the first request is still setting up the payment:** `409` "This order is still being
  set up. Try again in a few seconds."
- **A cart that can't be ordered** (closed, paused, sold out, outside the delivery area, below
  the minimum, a bad promo code): `422`, with the quote's messages under `errors`, keyed by field.
- **Stripe unreachable:** `503`. Retry with the same key; the order is kept.
- **The restaurant's processor isn't set up yet** (no Stripe keys, or no Square credentials or
  location): `503` "This restaurant isn’t taking payments online yet.", before any order is
  made.

### `GET /orders/{public_id}?token=…`

Order tracking. Pass the `tracking_token` from the order response, or sign in as the customer
who placed it. Anyone else gets `404`. Names, contact details and the street address are left
out, because a tracking link can be forwarded.

While the order is `pending_payment`, this also asks Stripe or Square whether it was paid, at
most every 10 seconds per order. A paid order is then `placed` even if the webhook never came.

```json
{
    "data": {
        "public_id": "01k6b2x0v8r5c9m3t7w4y1z6qe",
        "order_number": "042",
        "status": "accepted",
        "payment_status": "paid",
        "fulfilment_type": "delivery",
        "table": null,
        "scheduled_for": null,
        "estimated_ready_at": "2026-10-05T07:20:00Z",
        "placed_at": "2026-10-05T07:00:12Z",
        "accepted_at": "2026-10-05T07:01:03Z",
        "ready_at": null,
        "completed_at": null,
        "rejected_at": null,
        "cancelled_at": null,
        "refunded_at": null,
        "rejection_reason": null,
        "restaurant": { "slug": "himalayan-momo-house", "name": "Himalayan Momo House", "phone": "03 5550 0142" },
        "delivery": { "suburb": "Southbank", "postcode": "3006" },
        "items": [
            {
                "menu_item_id": 1,
                "name": "Steamed momo",
                "quantity": 2,
                "unit_price_cents": 1990,
                "line_total_cents": 3980,
                "notes": "Extra achar",
                "modifiers": [
                    { "modifier_option_id": 2, "group": "Choose filling", "name": "Pork", "price_delta_cents": 100 },
                    { "modifier_option_id": 4, "group": "Sauce", "name": "Tomato achar", "price_delta_cents": 100 },
                    { "modifier_option_id": 10, "group": "Spice level", "name": "Hot", "price_delta_cents": 0 }
                ]
            }
        ],
        "subtotal_cents": 3980,
        "delivery_fee_cents": 600,
        "discount_cents": 398,
        "total_cents": 4182,
        "gst_cents": 380,
        "promo_code": "MOMO10",
        "timeline": [
            { "status": "pending_payment", "at": "2026-10-05T06:59:40Z" },
            { "status": "placed", "at": "2026-10-05T07:00:12Z" },
            { "status": "accepted", "at": "2026-10-05T07:01:03Z" }
        ],
        "created_at": "2026-10-05T06:59:40Z"
    }
}
```

`order_number` is null until the order is paid. `table` is the table for a dine-in order (as it
was called when ordered), otherwise null. Names and prices in `items` are as ordered;
`menu_item_id` and `modifier_option_id` point at the menu (for "Order again") and are null
once that dish or option has been deleted.

### `POST /orders/{public_id}/square-payment`

Pays a Square order with the token from Square's payment form: the Web Payments SDK on the web
(a card is tokenised with its verification details, so Square can check it with the bank), the
In-App Payments SDK on iOS and Android.

```http
POST /api/v1/orders/01k6b2x0v8r5c9m3t7w4y1z6qe/square-payment
Content-Type: application/json
Idempotency-Key: 9b2e4c1d-…

{
    "tracking_token": "Gs3kq9…",
    "source_id": "cnon:CBASE…",
    "verification_token": null
}
```

| Field | Rules |
|---|---|
| `Idempotency-Key` header | Required, 8–100 characters. New for each try (each token), repeated unchanged on retries |
| `tracking_token` | Required: the order's own, from creating it |
| `source_id` | Required: the card, Apple Pay or Google Pay token |
| `verification_token` | Optional: from Square's separate buyer verification, where the SDK gives one |

- **`200 OK`:** paid. The order is `placed` and on its way to the kitchen:
  `{"data": {"order": { "status": "placed", "payment_status": "paid", "…": "as in GET /orders/{public_id}" }}}`.
  An order that's already paid answers the same, without charging again.
- **`202 Accepted`:** Square took the payment but hasn't finished it; its webhook places the
  order shortly. Show the order's page.
- **`402`:** the card was declined. The message says what to do, for example "Your card was
  declined: there isn’t enough money in the account. Try another card." Try again with another
  token and a new key.
- **`409`:** the order can't be paid this way: it timed out before it was paid (nothing was
  charged), it's paid with Stripe, or another payment for it is going through right now.
- **`404`:** an unknown order, or not its tracking token. **`422`:** a missing field or header.
- **`503`:** Square couldn't be reached. Try again; the order waits.

### `POST /stripe/webhook/{slug}`

For Stripe only: each restaurant's own Stripe account sends its events to its own endpoint,
which its back office shows (Restaurant settings → Payments). The `Stripe-Signature` header
must be a valid signature of the raw body with that restaurant's webhook signing secret, made
within the last 5 minutes; otherwise `400`. An unknown slug is `404`.

The platform acts on `payment_intent.succeeded` (the order is paid and placed) and
`payment_intent.payment_failed` (noted; the customer can try another card), and acknowledges
any other event. An event only ever affects that restaurant's orders. Each event is stored
once per restaurant, by its ID: a replay gets `200` with `"duplicate": true` and changes
nothing (restaurants sharing a Stripe account each get their own copy). A payment that
arrives after its checkout was cancelled is refunded automatically.

Events are processed during the request, so a paid order reaches the kitchen within a second.
If processing fails, Stripe still gets `200` and the event is retried on the queue (five
tries, 30 seconds apart).

### `POST /square/webhook/{slug}`

For Square: each restaurant's own Square application sends its events to its own endpoint,
which its back office shows (Restaurant settings → Payments → Square). The
`x-square-hmacsha256-signature` header must be the HMAC-SHA256 of that URL (as
`<APP_URL>/api/v1/square/webhook/<slug>`) followed by the raw body, keyed with the
subscription's signature key, which the owner entered; otherwise `400` (also while no key is
entered). An unknown slug is `404`.

The platform acts on:
- `payment.created` / `payment.updated` with status `COMPLETED`: the restaurant's order, found
  by its payment or by `reference_id`, is paid and placed, if its answer never reached the API;
- `refund.updated` with status `FAILED` or `REJECTED`: logged, to refund by hand.

Anything else, including the restaurant's in-person Square sales, is acknowledged and ignored.
An event only ever affects that restaurant's orders, and is stored once per restaurant: a
replay gets `200` with `"duplicate": true`.

## Customer accounts (orders, addresses, notifications)

### `GET /me/orders`

The signed-in customer's paid (or refunded) orders, newest first, 15 a page (`?page=2`). Each
order is shaped as in `GET /orders/{public_id}`, with Laravel's `links` and `meta` for paging.

### Me addresses

Saved delivery addresses. `GET /me/addresses` lists them; `POST /me/addresses` adds one;
`PATCH /me/addresses/{id}` changes one; `DELETE /me/addresses/{id}` removes one (`204`).
Another customer's address is `404`.

```json
{ "label": "Home", "line1": "12 Southbank Boulevard", "line2": null, "suburb": "Southbank", "state": "VIC", "postcode": "3006", "delivery_instructions": "Buzz 1204" }
```

`line1`, `suburb`, `state` and a 4-digit `postcode` are required.

### `POST /push-tokens`

Registers a device for push notifications (Expo). Signed in, the device gets notifications
about the customer's orders at the restaurant:

```json
{ "expo_push_token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]", "platform": "ios", "restaurant": "himalayan-momo-house" }
```

As a guest, for one order, proved by its tracking token:

```json
{ "expo_push_token": "ExponentPushToken[xxxxxxxxxxxxxxxxxxxxxx]", "platform": "web", "order": { "public_id": "01k6b2x0v8r5c9m3t7w4y1z6qe", "tracking_token": "Gs3kq9…" } }
```

`201 Created`. `platform` is `ios`, `android` or `web`. A wrong tracking token is `404`. A
token Expo reports as no longer registered is deleted.

Notifications are sent on each status change the customer cares about, on the Android
channel `default` (the app names it "Order updates"), with
`data: { "public_id": "…", "status": "accepted" }`, so a tap can open the order.

## Kitchen (staff)

For staff and owners of the restaurant; anyone else gets `403`. Staff who work at more than
one restaurant say which with `restaurant` (its slug) in the query or body. Orders are
addressed by `public_id`.

### `GET /staff/orders`

The orders the kitchen still has to act on (`placed`, `accepted`, `preparing`, `ready`,
`out_for_delivery`), oldest first. `?status=placed,accepted` narrows it.

```json
{
    "data": [
        {
            "public_id": "01k6b2x0v8r5c9m3t7w4y1z6qe",
            "order_number": "042",
            "status": "placed",
            "payment_status": "paid",
            "fulfilment_type": "delivery",
            "table": null,
            "scheduled_for": null,
            "placed_at": "2026-10-05T07:00:12Z",
            "accept_by": "2026-10-05T07:10:12Z",
            "accepted_at": null,
            "prep_minutes": null,
            "estimated_ready_at": null,
            "ready_at": null,
            "customer": { "name": "Sam Taylor", "phone": "0491 570 110", "email": "sam@example.com" },
            "delivery": { "line1": "12 Southbank Boulevard", "line2": "Apartment 1204", "suburb": "Southbank", "state": "VIC", "postcode": "3006", "instructions": "Buzz 1204" },
            "notes": "Ring when you’re here",
            "items": [ { "name": "Steamed momo", "quantity": 2, "…": "as in GET /orders/{public_id}" } ],
            "total_cents": 4182
        }
    ]
}
```

`accept_by` is set on new (`placed`) orders: if nobody accepts the order by then, it's
rejected and refunded automatically. For an order placed while the restaurant was closed, the
clock starts when it opens.

### `POST /staff/orders/{public_id}/accept`

`{ "prep_minutes": 15 }` (5–180). Sets `estimated_ready_at` to now plus the prep time, or keeps
a scheduled order's promised time if that's later. Returns the order as above.

`POST /staff/orders/{public_id}/reject`: `{ "reason": "We’ve run out of pork" }` (required,
3–200 characters, shown to the customer). The order is refunded in full.

`POST /staff/orders/{public_id}/status`: `{ "status": "preparing", "note": null }`. `status` is
`preparing`, `ready`, `out_for_delivery`, `completed` or `cancelled` (refunded if paid). A move
the lifecycle doesn't allow is `422`: "An order that’s ready can’t be marked preparing."

### `PATCH /staff/restaurant`

`{ "is_accepting_orders": false }` pauses online ordering; `true` resumes it. Returns
`{ "data": { "slug": "…", "is_accepting_orders": false } }`.

### `PATCH /staff/menu-items/{id}`

`{ "is_available": false }` marks an item sold out; `PATCH /staff/modifier-options/{id}` does
the same for an option. The menu shows the change on its next request.

## Realtime

Laravel Reverb, over the Pusher protocol (`laravel-echo` with `pusher-js`). Every status
change broadcasts an `order.updated` event (listen for `.order.updated`) on two channels:

| Channel | Who | Authorisation |
|---|---|---|
| `private-restaurant.{restaurant id}.orders` | The restaurant's staff and owners | `POST /api/v1/broadcasting/auth` with the bearer token |
| `order.{public_id}` | The customer's tracking screen | None (public); the ID is unguessable |

The payload carries status and times only, never names, addresses or items. Fetch the
details from the API when you need them.

Events are sent as the change is saved, not queued. Live updates are best-effort: if Reverb
can't be reached, the change is still saved (the failure is logged), and screens catch up by
polling.

```json
{
    "public_id": "01k6b2x0v8r5c9m3t7w4y1z6qe",
    "order_number": "042",
    "status": "accepted",
    "fulfilment_type": "delivery",
    "scheduled_for": null,
    "placed_at": "2026-10-05T07:00:12Z",
    "accepted_at": "2026-10-05T07:01:03Z",
    "estimated_ready_at": "2026-10-05T07:20:00Z",
    "ready_at": null,
    "completed_at": null,
    "rejected_at": null,
    "cancelled_at": null,
    "updated_at": "2026-10-05T07:01:03Z"
}
```

If the socket disconnects, poll the same endpoints every 10 seconds until it's back.

## Accounts

Customers and staff sign in the same way and get a Sanctum token. Send it as
`Authorization: Bearer <token>` until signing out. Tokens don't expire; they're revoked
by `POST /auth/logout` or by deleting the account.

### `POST /auth/register`

Creates a customer account and signs it in. Limited to 10 an hour per IP address.

```http
POST /api/v1/auth/register
Content-Type: application/json

{
    "name": "Sam Taylor",
    "email": "sam@example.com",
    "phone": "0491 570 110",
    "password": "momos-for-dinner",
    "device_name": "iOS app"
}
```

| Field | Rules |
|---|---|
| `name` | Required, up to 255 characters |
| `email` | Required, a valid email; stored lower-case. Must not belong to an existing account |
| `phone` | Optional, up to 32 characters |
| `password` | Required, at least 8 characters |
| `device_name` | Required; names the token, e.g. "iOS app" or "Website" |

`201 Created`

```json
{
    "data": {
        "token": "3|kD8sX…",
        "user": {
            "id": 42,
            "name": "Sam Taylor",
            "email": "sam@example.com",
            "phone": "0491 570 110",
            "restaurants": [],
            "created_at": "2026-10-02T08:15:00Z"
        }
    }
}
```

`422` when the email is taken:

```json
{
    "message": "An account with this email already exists. Sign in instead.",
    "errors": { "email": ["An account with this email already exists. Sign in instead."] }
}
```

### `POST /auth/login`

Signs in and returns a new token. Limited to 5 attempts a minute per email and IP address,
and 20 a minute per IP address.

```http
POST /api/v1/auth/login
Content-Type: application/json

{ "email": "sam@example.com", "password": "momos-for-dinner", "device_name": "Website" }
```

`200 OK`: the same body as registering. A wrong password and an unknown email give the
same answer, so the API doesn't reveal who has an account:

```json
{
    "message": "The email or password is incorrect.",
    "errors": { "email": ["The email or password is incorrect."] }
}
```

### `POST /auth/logout`

Revokes the token the request was made with. Other devices stay signed in. `204 No Content`.

### `GET /me`

The signed-in user. `restaurants` lists the restaurants where they're staff or an owner,
which the app uses to offer the kitchen screens.

```json
{
    "data": {
        "id": 7,
        "name": "Demo Staff",
        "email": "staff@example.com",
        "phone": "0491 570 157",
        "restaurants": [
            { "id": 1, "slug": "himalayan-momo-house", "name": "Himalayan Momo House", "role": "staff" }
        ],
        "created_at": "2026-09-29T09:00:00Z"
    }
}
```

`401` without a valid token.

### `DELETE /me`

Deletes the signed-in customer's account: the account, saved addresses, push tokens and
every sign-in. Past orders stay as financial records, but everything that identifies the
person is removed from them: the name becomes "Deleted customer", and the phone, email,
street address, delivery instructions and order notes are cleared. The suburb, postcode,
items and totals remain. `204 No Content`.

Staff and owner accounts can't be deleted this way, so a restaurant is never left without
its owner. `403`:

```json
{
    "message": "Accounts with staff or owner access to a restaurant can’t be deleted in the app. Ask an owner of the restaurant to remove your access in the back office, then try again."
}
```
