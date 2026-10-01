import { keepPreviousData, useQuery } from '@tanstack/react-query';

import type { CartLine } from '@/cart/cart-store';
import { apiRequest } from '@/lib/api/client';
import { dataOf, quoteSchema, slotsSchema, type FulfilmentType } from '@/lib/api/schemas';
import { config } from '@/lib/config';

const base = `/restaurants/${encodeURIComponent(config.restaurantSlug)}`;

export interface CartRequest {
  fulfilment_type: FulfilmentType;
  postcode: string | null;
  scheduled_for: string | null;
  promo_code: string | null;
  items: { menu_item_id: number; quantity: number; modifier_option_ids: number[]; notes: string | null }[];
}

/** The cart as the API takes it: IDs, quantities and choices, never prices. */
export function cartRequest(cart: {
  lines: CartLine[];
  fulfilment: FulfilmentType;
  postcode: string | null;
  scheduledFor: string | null;
  promoCode: string | null;
}): CartRequest {
  return {
    fulfilment_type: cart.fulfilment,
    postcode: cart.fulfilment === 'delivery' ? cart.postcode : null,
    scheduled_for: cart.scheduledFor,
    promo_code: cart.promoCode,
    items: cart.lines.map((line) => ({
      menu_item_id: line.menuItemId,
      quantity: line.quantity,
      modifier_option_ids: line.optionIds,
      notes: line.notes,
    })),
  };
}

/**
 * The server's prices for the cart and anything stopping it being ordered. Re-quoted when
 * the cart changes; the previous quote stays on screen meanwhile.
 */
export function useQuote(request: CartRequest | null) {
  return useQuery({
    queryKey: ['quote', config.restaurantSlug, request],
    queryFn: ({ signal }) =>
      apiRequest(`${base}/orders/quote`, { method: 'POST', body: request, schema: dataOf(quoteSchema), signal }),
    enabled: request !== null && request.items.length > 0,
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

/** ASAP availability and the times on offer for later. */
export function useSlots(fulfilment: FulfilmentType, postcode: string | null) {
  return useQuery({
    queryKey: ['slots', config.restaurantSlug, fulfilment, postcode],
    queryFn: ({ signal }) => {
      const query = new URLSearchParams({ fulfilment_type: fulfilment });

      if (fulfilment === 'delivery' && postcode) {
        query.set('postcode', postcode);
      }

      return apiRequest(`${base}/slots?${query.toString()}`, { schema: dataOf(slotsSchema), signal });
    },
    staleTime: 60_000,
  });
}
