import { z } from 'zod';

/*
 * Response shapes, kept in step with docs/API.md. Field names stay as the API sends them
 * (snake_case). Money is integer cents; timestamps are ISO 8601 in UTC; restaurant-local
 * times such as opening hours are HH:MM strings.
 */

export const addressSchema = z.object({
  line1: z.string().nullish(),
  line2: z.string().nullish(),
  suburb: z.string().nullish(),
  state: z.string().nullish(),
  postcode: z.string().nullish(),
  country: z.string().nullish(),
});

export const deliveryZoneSchema = z.object({
  name: z.string(),
  postcodes: z.array(z.string()),
  fee_cents: z.number().int(),
  min_order_cents: z.number().int(),
  estimated_minutes: z.number().int(),
});

export const openingHourSchema = z.object({
  day_of_week: z.number().int().min(0).max(6),
  opens_at: z.string(),
  closes_at: z.string(),
});

export const specialHourSchema = z.object({
  date: z.string(),
  is_closed: z.boolean(),
  opens_at: z.string().nullable(),
  closes_at: z.string().nullable(),
  note: z.string().nullable(),
});

export const restaurantStatusSchema = z.object({
  is_open: z.boolean(),
  is_accepting_orders: z.boolean(),
  can_order_asap: z.boolean(),
  closes_at: z.string().nullable(),
  next_opening_at: z.string().nullable(),
});

export const restaurantSchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  phone: z.string().nullable(),
  email: z.string().nullable(),
  address: addressSchema.nullable(),
  abn: z.string().nullable(),
  timezone: z.string(),
  currency: z.string(),
  brand_color: z.string(),
  logo_url: z.string().nullable(),
  cover_image_url: z.string().nullable(),
  status: restaurantStatusSchema,
  fulfilment: z.object({
    pickup: z.object({ enabled: z.boolean(), prep_minutes: z.number().int() }),
    delivery: z.object({ enabled: z.boolean(), zones: z.array(deliveryZoneSchema) }),
    dine_in: z.object({ enabled: z.boolean(), tables: z.array(z.string()) }),
  }),
  opening_hours: z.array(openingHourSchema),
  special_hours: z.array(specialHourSchema),
  /**
   * How customers pay: with the restaurant's own Stripe account (its publishable key) or its
   * own Square account (its Square application and location), once set up.
   */
  payments: z
    .object({
      processor: z.enum(['stripe', 'square']).default('stripe'),
      stripe_publishable_key: z.string().nullable(),
      square: z
        .object({
          application_id: z.string(),
          location_id: z.string(),
          environment: z.enum(['sandbox', 'production']),
        })
        .nullable()
        .optional(),
    })
    .optional(),
});

export const membershipSchema = z.object({
  id: z.number().int(),
  slug: z.string(),
  name: z.string(),
  role: z.enum(['owner', 'staff']),
});

export const userSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  email: z.string(),
  phone: z.string().nullable(),
  restaurants: z.array(membershipSchema),
  created_at: z.string().nullable(),
});

export const signedInSchema = z.object({
  token: z.string(),
  user: userSchema,
});

/* Menu (GET /restaurants/{slug}/menu) */

const labelledSchema = z.object({ value: z.string(), label: z.string() });

export const modifierOptionSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  price_delta_cents: z.number().int(),
  is_available: z.boolean(),
});

export const modifierGroupSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  min_select: z.number().int(),
  max_select: z.number().int(),
  selection_rule: z.string(),
  options: z.array(modifierOptionSchema),
});

export const menuItemSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  description: z.string().nullable(),
  price_cents: z.number().int(),
  image_url: z.string().nullable(),
  is_available: z.boolean(),
  dietary_tags: z.array(labelledSchema),
  allergens: z.array(labelledSchema),
  modifier_groups: z.array(modifierGroupSchema),
});

export const menuCategorySchema = z.object({
  id: z.number().int(),
  name: z.string(),
  description: z.string().nullable(),
  items: z.array(menuItemSchema),
});

export const menuSchema = z.object({ categories: z.array(menuCategorySchema) });

/* Ordering (slots, delivery check, quote) */

export const fulfilmentTypeSchema = z.enum(['pickup', 'delivery', 'dine_in']);

export const slotsSchema = z.object({
  fulfilment_type: fulfilmentTypeSchema,
  asap: z.object({ available: z.boolean(), estimated_minutes: z.number().int() }),
  slots: z.array(z.string()),
});

export const quoteErrorSchema = z.object({
  code: z.string(),
  message: z.string(),
  field: z.string().nullable(),
});

export const quoteSchema = z.object({
  can_place_order: z.boolean(),
  lines: z.array(
    z.object({
      menu_item_id: z.number().int(),
      name: z.string().nullable(),
      quantity: z.number().int(),
      unit_price_cents: z.number().int(),
      line_total_cents: z.number().int(),
      modifiers: z.array(
        z.object({ id: z.number().int(), group: z.string().nullable(), name: z.string(), price_delta_cents: z.number().int() }),
      ),
      notes: z.string().nullable(),
      errors: z.array(z.string()),
    }),
  ),
  subtotal_cents: z.number().int(),
  delivery_fee_cents: z.number().int(),
  discount_cents: z.number().int(),
  total_cents: z.number().int(),
  gst_cents: z.number().int(),
  promo_code: z.object({ code: z.string(), description: z.string() }).nullable(),
  fulfilment: z.object({
    type: fulfilmentTypeSchema,
    estimated_minutes: z.number().int(),
    delivery_zone: deliveryZoneSchema.nullable(),
    table: z.string().nullable(),
  }),
  scheduled_for: z.string().nullable(),
  errors: z.array(quoteErrorSchema),
});

/* Orders */

export const orderStatusSchema = z.enum([
  'pending_payment',
  'placed',
  'accepted',
  'preparing',
  'ready',
  'out_for_delivery',
  'completed',
  'rejected',
  'cancelled',
]);

export const orderItemSchema = z.object({
  menu_item_id: z.number().int().nullable(),
  name: z.string(),
  quantity: z.number().int(),
  unit_price_cents: z.number().int(),
  line_total_cents: z.number().int(),
  notes: z.string().nullable(),
  modifiers: z.array(
    z.object({
      modifier_option_id: z.number().int().nullable(),
      group: z.string(),
      name: z.string(),
      price_delta_cents: z.number().int(),
    }),
  ),
});

export const orderSchema = z.object({
  public_id: z.string(),
  order_number: z.string().nullable(),
  status: orderStatusSchema,
  payment_status: z.enum(['unpaid', 'paid', 'failed', 'refunded']),
  fulfilment_type: fulfilmentTypeSchema,
  table: z.string().nullable(),
  scheduled_for: z.string().nullable(),
  estimated_ready_at: z.string().nullable(),
  placed_at: z.string().nullable(),
  accepted_at: z.string().nullable(),
  ready_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  rejected_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
  refunded_at: z.string().nullable(),
  rejection_reason: z.string().nullable(),
  restaurant: z.object({ slug: z.string(), name: z.string(), phone: z.string().nullable() }),
  delivery: z.object({ suburb: z.string().nullable(), postcode: z.string().nullable() }).nullable(),
  items: z.array(orderItemSchema),
  subtotal_cents: z.number().int(),
  delivery_fee_cents: z.number().int(),
  discount_cents: z.number().int(),
  total_cents: z.number().int(),
  gst_cents: z.number().int(),
  promo_code: z.string().nullable(),
  timeline: z.array(z.object({ status: orderStatusSchema, at: z.string().nullable() })),
  created_at: z.string().nullable(),
});

export const checkoutSchema = z.object({
  order: orderSchema,
  tracking_token: z.string(),
  /** With Stripe, the PaymentIntent to confirm; with Square, nothing yet (the app sends its token). */
  payment: z.discriminatedUnion('processor', [
    z.object({
      processor: z.literal('stripe'),
      payment_intent_id: z.string(),
      client_secret: z.string(),
      status: z.string(),
      amount_cents: z.number().int(),
      currency: z.string(),
    }),
    z.object({
      processor: z.literal('square'),
      amount_cents: z.number().int(),
      currency: z.string(),
    }),
  ]),
});

/** A Square order after its payment: placed, or still waiting while Square finishes it. */
export const squarePaymentSchema = z.object({ order: orderSchema });

export const myOrdersSchema = z.object({
  data: z.array(orderSchema),
  meta: z.object({ current_page: z.number().int(), last_page: z.number().int(), total: z.number().int() }),
});

/* Kitchen (GET /staff/orders): everything needed to make, hand over and deliver an order */

export const staffOrderSchema = z.object({
  public_id: z.string(),
  order_number: z.string().nullable(),
  status: orderStatusSchema,
  payment_status: z.enum(['unpaid', 'paid', 'failed', 'refunded']),
  fulfilment_type: fulfilmentTypeSchema,
  table: z.string().nullable(),
  scheduled_for: z.string().nullable(),
  placed_at: z.string().nullable(),
  accept_by: z.string().nullable(),
  accepted_at: z.string().nullable(),
  prep_minutes: z.number().int().nullable(),
  estimated_ready_at: z.string().nullable(),
  ready_at: z.string().nullable(),
  customer: z.object({ name: z.string().nullable(), phone: z.string().nullable(), email: z.string().nullable() }),
  delivery: z
    .object({
      line1: z.string().nullable(),
      line2: z.string().nullable(),
      suburb: z.string().nullable(),
      state: z.string().nullable(),
      postcode: z.string().nullable(),
      instructions: z.string().nullable(),
    })
    .nullable(),
  notes: z.string().nullable(),
  items: z.array(orderItemSchema),
  total_cents: z.number().int(),
});

/* Saved addresses */

export const savedAddressSchema = z.object({
  id: z.number().int(),
  label: z.string().nullable(),
  line1: z.string(),
  line2: z.string().nullable(),
  suburb: z.string(),
  state: z.string(),
  postcode: z.string(),
  delivery_instructions: z.string().nullable(),
});

/** Most endpoints wrap their payload in `data`. */
export function dataOf<T extends z.ZodType>(schema: T): z.ZodType<z.output<T>> {
  return z.object({ data: schema }).transform((body) => (body as { data: z.output<T> }).data);
}

export type Restaurant = z.infer<typeof restaurantSchema>;
export type RestaurantStatus = z.infer<typeof restaurantStatusSchema>;
export type DeliveryZone = z.infer<typeof deliveryZoneSchema>;
export type User = z.infer<typeof userSchema>;
export type SignedIn = z.infer<typeof signedInSchema>;
export type Menu = z.infer<typeof menuSchema>;
export type MenuCategory = z.infer<typeof menuCategorySchema>;
export type MenuItem = z.infer<typeof menuItemSchema>;
export type ModifierGroup = z.infer<typeof modifierGroupSchema>;
export type ModifierOption = z.infer<typeof modifierOptionSchema>;
export type FulfilmentType = z.infer<typeof fulfilmentTypeSchema>;
export type Slots = z.infer<typeof slotsSchema>;
export type Quote = z.infer<typeof quoteSchema>;
export type QuoteError = z.infer<typeof quoteErrorSchema>;
export type OrderStatus = z.infer<typeof orderStatusSchema>;
export type Order = z.infer<typeof orderSchema>;
export type OrderItem = z.infer<typeof orderItemSchema>;
export type Checkout = z.infer<typeof checkoutSchema>;
export type SavedAddress = z.infer<typeof savedAddressSchema>;
export type StaffOrder = z.infer<typeof staffOrderSchema>;
