import { useInfiniteQuery, useMutation, useQuery } from '@tanstack/react-query';

import { useSession } from '@/auth/session';
import { apiRequest } from '@/lib/api/client';
import type { CartRequest } from '@/lib/api/ordering';
import { checkoutSchema, dataOf, myOrdersSchema, orderSchema, squarePaymentSchema, type Order } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { useRealtimeStatus } from '@/lib/realtime';

export interface OrderRequest extends CartRequest {
  customer: { name: string; phone: string; email: string };
  delivery: { line1: string; line2: string | null; suburb: string; state: string | null; instructions: string | null } | null;
  notes: string | null;
  /** This device's Expo push token, when notifications are already allowed. */
  push_token?: string | null;
}

export const orderQueryKey = (publicId: string) => ['order', publicId] as const;

/**
 * Creates the order, and with Stripe its PaymentIntent. The Idempotency-Key must stay the
 * same when the same order is retried, so a retry never creates a second one.
 */
export function usePlaceOrder() {
  return useMutation({
    mutationFn: ({ request, idempotencyKey }: { request: OrderRequest; idempotencyKey: string }) =>
      apiRequest(`/restaurants/${encodeURIComponent(config.restaurantSlug)}/orders`, {
        method: 'POST',
        body: request,
        headers: { 'Idempotency-Key': idempotencyKey },
        schema: dataOf(checkoutSchema),
      }),
  });
}

/**
 * Pays a Square order with the token from Square's payment form (card, Apple Pay or Google
 * Pay). The Idempotency-Key is new for each token, so a retried request is charged once.
 * Declines (402) and orders that can't be paid any more (409) come with a message to show.
 */
export function paySquareOrder(
  publicId: string,
  body: { tracking_token: string; source_id: string; verification_token?: string | null },
  idempotencyKey: string,
) {
  return apiRequest(`/orders/${encodeURIComponent(publicId)}/square-payment`, {
    method: 'POST',
    body,
    headers: { 'Idempotency-Key': idempotencyKey },
    schema: dataOf(squarePaymentSchema),
  });
}

/** Statuses after which nothing changes. */
export const FINAL_STATUSES: Order['status'][] = ['completed', 'rejected', 'cancelled'];

/**
 * One order, for its tracking screen. Live updates arrive over Reverb (the screen refetches
 * on each); while the socket is down, or while payment is still being confirmed, it polls.
 */
export function useOrder(publicId: string, trackingToken: string | null) {
  const realtime = useRealtimeStatus();
  const signedIn = useSession((state) => state.status === 'signedIn');

  return useQuery({
    queryKey: orderQueryKey(publicId),
    queryFn: ({ signal }) =>
      apiRequest(
        `/orders/${encodeURIComponent(publicId)}${trackingToken ? `?token=${encodeURIComponent(trackingToken)}` : ''}`,
        { schema: dataOf(orderSchema), signal },
      ),
    enabled: trackingToken !== null || signedIn,
    refetchInterval: (query) => {
      const status = query.state.data?.status;

      if (status && FINAL_STATUSES.includes(status)) {
        return false;
      }

      // Stripe's (or Square's) confirmation usually lands within seconds of paying.
      if (status === 'pending_payment') {
        return 3_000;
      }

      return realtime === 'connected' ? false : 10_000;
    },
  });
}

/** The signed-in customer's past orders, 15 at a time. */
export function useMyOrders() {
  const signedIn = useSession((state) => state.status === 'signedIn');

  return useInfiniteQuery({
    queryKey: ['my-orders'],
    queryFn: ({ pageParam, signal }) => apiRequest(`/me/orders?page=${pageParam}`, { schema: myOrdersSchema, signal }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => (lastPage.meta.current_page < lastPage.meta.last_page ? lastPage.meta.current_page + 1 : undefined),
    enabled: signedIn,
  });
}
