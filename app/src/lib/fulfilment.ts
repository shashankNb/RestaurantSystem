import type { FulfilmentType, Restaurant } from '@/lib/api/schemas';

const LABELS: Record<FulfilmentType, string> = {
  pickup: 'Pickup',
  delivery: 'Delivery',
  dine_in: 'Dine in',
};

/** The ways the restaurant takes orders right now, for the pickup/delivery/dine-in switch. */
export function fulfilmentOptions(restaurant: Restaurant | undefined): { value: FulfilmentType; label: string }[] {
  const offered: Record<FulfilmentType, boolean> = {
    pickup: restaurant?.fulfilment.pickup.enabled ?? true,
    delivery: restaurant?.fulfilment.delivery.enabled ?? false,
    dine_in: restaurant?.fulfilment.dine_in.enabled ?? false,
  };

  return (['pickup', 'delivery', 'dine_in'] as const)
    .filter((type) => offered[type])
    .map((type) => ({ value: type, label: LABELS[type] }));
}

export function fulfilmentLabel(type: FulfilmentType): string {
  return LABELS[type];
}
