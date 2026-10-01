import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';

import { apiRequest } from '@/lib/api/client';
import { menuQueryKey } from '@/lib/api/menu';
import { FINAL_STATUSES } from '@/lib/api/orders';
import { restaurantQueryKey } from '@/lib/api/restaurant';
import { dataOf, staffOrderSchema, type Menu, type OrderStatus, type Restaurant, type StaffOrder } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { useRealtimeStatus } from '@/lib/realtime';

export const staffOrdersKey = ['staff-orders', config.restaurantSlug] as const;

const staffOrder = dataOf(staffOrderSchema);

/**
 * The orders the kitchen still has to act on, oldest first. Live updates refetch it; it
 * also checks every minute, and every 10 seconds while the live connection is down. It
 * keeps checking in a background browser tab, so alerts still sound there.
 */
export function useStaffOrders() {
  const realtime = useRealtimeStatus();

  return useQuery({
    queryKey: staffOrdersKey,
    queryFn: ({ signal }) =>
      apiRequest(`/staff/orders?restaurant=${encodeURIComponent(config.restaurantSlug)}`, {
        schema: dataOf(z.array(staffOrderSchema)),
        signal,
      }),
    refetchInterval: realtime === 'connected' ? 60_000 : 10_000,
    refetchIntervalInBackground: true,
  });
}

/** Puts an order the API returned into the queue, or takes it off once it's finished. */
function useUpdateQueue() {
  const queryClient = useQueryClient();

  return (order: StaffOrder) => {
    queryClient.setQueryData<StaffOrder[]>(staffOrdersKey, (orders) =>
      orders === undefined
        ? orders
        : FINAL_STATUSES.includes(order.status)
          ? orders.filter((candidate) => candidate.public_id !== order.public_id)
          : orders.map((candidate) => (candidate.public_id === order.public_id ? order : candidate)),
    );
    void queryClient.invalidateQueries({ queryKey: staffOrdersKey });
  };
}

const orderPath = (order: StaffOrder) => `/staff/orders/${encodeURIComponent(order.public_id)}`;

/**
 * Accepts an order with a prep time. An order for as soon as possible goes straight on to
 * "preparing" (the board has no separate accepted column); one for later waits there until
 * the kitchen starts it.
 */
export function useAcceptOrder() {
  const updateQueue = useUpdateQueue();

  return useMutation({
    mutationFn: async ({ order, prepMinutes }: { order: StaffOrder; prepMinutes: number }) => {
      const accepted = await apiRequest(`${orderPath(order)}/accept`, {
        method: 'POST',
        body: { prep_minutes: prepMinutes },
        schema: staffOrder,
      });

      if (order.scheduled_for !== null) {
        return accepted;
      }

      try {
        return await apiRequest(`${orderPath(order)}/status`, { method: 'POST', body: { status: 'preparing' }, schema: staffOrder });
      } catch {
        // Accepted all the same; "Start preparing" is on its card.
        return accepted;
      }
    },
    onSuccess: updateQueue,
  });
}

/** Rejects an order; the customer is refunded in full and told the reason. */
export function useRejectOrder() {
  const updateQueue = useUpdateQueue();

  return useMutation({
    mutationFn: ({ order, reason }: { order: StaffOrder; reason: string }) =>
      apiRequest(`${orderPath(order)}/reject`, { method: 'POST', body: { reason }, schema: staffOrder }),
    onSuccess: updateQueue,
  });
}

/** Moves an order along, or cancels it (refunding a paid order). */
export function useAdvanceOrder() {
  const updateQueue = useUpdateQueue();

  return useMutation({
    mutationFn: ({ order, status, note }: { order: StaffOrder; status: Extract<OrderStatus, 'preparing' | 'ready' | 'out_for_delivery' | 'completed' | 'cancelled'>; note?: string }) =>
      apiRequest(`${orderPath(order)}/status`, {
        method: 'POST',
        body: { status, ...(note ? { note } : {}) },
        schema: staffOrder,
      }),
    onSuccess: updateQueue,
  });
}

/** Pauses or resumes online ordering. Shown straight away; put back if the API refuses. */
export function useSetAcceptingOrders() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (accepting: boolean) =>
      apiRequest('/staff/restaurant', {
        method: 'PATCH',
        body: { is_accepting_orders: accepting, restaurant: config.restaurantSlug },
      }),
    onMutate: async (accepting) => {
      await queryClient.cancelQueries({ queryKey: restaurantQueryKey });
      const previous = queryClient.getQueryData<Restaurant>(restaurantQueryKey);
      queryClient.setQueryData<Restaurant>(restaurantQueryKey, (restaurant) =>
        restaurant ? { ...restaurant, status: { ...restaurant.status, is_accepting_orders: accepting } } : restaurant,
      );

      return { previous };
    },
    onError: (_error, _accepting, context) => queryClient.setQueryData(restaurantQueryKey, context?.previous),
    onSettled: () => queryClient.invalidateQueries({ queryKey: restaurantQueryKey }),
  });
}

/** Marks a dish sold out or available again. */
export function useSetItemAvailable() {
  return useAvailability((menu, id, available) => ({
    categories: menu.categories.map((category) => ({
      ...category,
      items: category.items.map((item) => (item.id === id ? { ...item, is_available: available } : item)),
    })),
  }), (id) => `/staff/menu-items/${id}`);
}

/** Marks an option (such as "Pork") sold out or available again, wherever it's offered. */
export function useSetOptionAvailable() {
  return useAvailability((menu, id, available) => ({
    categories: menu.categories.map((category) => ({
      ...category,
      items: category.items.map((item) => ({
        ...item,
        modifier_groups: item.modifier_groups.map((group) => ({
          ...group,
          options: group.options.map((option) => (option.id === id ? { ...option, is_available: available } : option)),
        })),
      })),
    })),
  }), (id) => `/staff/modifier-options/${id}`);
}

function useAvailability(apply: (menu: Menu, id: number, available: boolean) => Menu, path: (id: number) => string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, available }: { id: number; available: boolean }) =>
      apiRequest(path(id), { method: 'PATCH', body: { is_available: available } }),
    onMutate: async ({ id, available }) => {
      await queryClient.cancelQueries({ queryKey: menuQueryKey });
      const previous = queryClient.getQueryData<Menu>(menuQueryKey);
      queryClient.setQueryData<Menu>(menuQueryKey, (menu) => (menu ? apply(menu, id, available) : menu));

      return { previous };
    },
    onError: (_error, _variables, context) => queryClient.setQueryData(menuQueryKey, context?.previous),
    onSettled: () => queryClient.invalidateQueries({ queryKey: menuQueryKey }),
  });
}
