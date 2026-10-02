import AsyncStorage from '@react-native-async-storage/async-storage';
import { Platform } from 'react-native';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import type { FulfilmentType } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { randomId } from '@/lib/random-id';

export interface CartLine {
  id: string;
  menuItemId: number;
  name: string;
  /** Item plus chosen options, for showing before the server's quote arrives. */
  unitPriceCents: number;
  quantity: number;
  optionIds: number[];
  /** The chosen options' names, e.g. "Pork, Tomato achar, Hot". */
  optionSummary: string;
  notes: string | null;
}

export type NewCartLine = Omit<CartLine, 'id'>;

interface CartState {
  lines: CartLine[];
  fulfilment: FulfilmentType;
  postcode: string | null;
  /** ISO time from /slots, or null for as soon as possible. */
  scheduledFor: string | null;
  promoCode: string | null;
  /** Dine in: the table's label, chosen in the cart or by the table's QR code. */
  table: string | null;
  add: (line: NewCartLine) => void;
  replace: (id: string, line: NewCartLine) => void;
  setQuantity: (id: string, quantity: number) => void;
  remove: (id: string) => void;
  clear: () => void;
  setFulfilment: (fulfilment: FulfilmentType) => void;
  setPostcode: (postcode: string | null) => void;
  setScheduledFor: (scheduledFor: string | null) => void;
  setPromoCode: (promoCode: string | null) => void;
  setTable: (table: string | null) => void;
}

export const MAX_QUANTITY = 50;

/** The same dish with the same choices and notes shares a line. */
const choiceKey = (line: NewCartLine) =>
  `${line.menuItemId}|${[...line.optionIds].sort((a, b) => a - b).join(',')}|${line.notes ?? ''}`;

/**
 * The cart, saved on this device per restaurant. Prices here are for display only: the
 * cart and checkout screens show the server's quote, and the server charges its own total.
 */
export const useCart = create<CartState>()(
  persist(
    (set) => ({
      lines: [],
      fulfilment: 'pickup',
      postcode: null,
      scheduledFor: null,
      promoCode: null,
      table: null,

      add: (line) =>
        set((state) => {
          const existing = state.lines.find((candidate) => choiceKey(candidate) === choiceKey(line));

          if (existing) {
            return {
              lines: state.lines.map((candidate) =>
                candidate.id === existing.id
                  ? { ...candidate, quantity: Math.min(MAX_QUANTITY, candidate.quantity + line.quantity) }
                  : candidate,
              ),
            };
          }

          return { lines: [...state.lines, { ...line, id: randomId() }] };
        }),

      replace: (id, line) =>
        set((state) => ({ lines: state.lines.map((candidate) => (candidate.id === id ? { ...line, id } : candidate)) })),

      setQuantity: (id, quantity) =>
        set((state) => ({
          lines:
            quantity < 1
              ? state.lines.filter((line) => line.id !== id)
              : state.lines.map((line) => (line.id === id ? { ...line, quantity: Math.min(MAX_QUANTITY, quantity) } : line)),
        })),

      remove: (id) => set((state) => ({ lines: state.lines.filter((line) => line.id !== id) })),

      clear: () => set({ lines: [], scheduledFor: null, promoCode: null }),

      setFulfilment: (fulfilment) => set({ fulfilment, scheduledFor: null }),
      setPostcode: (postcode) => set({ postcode }),
      setScheduledFor: (scheduledFor) => set({ scheduledFor }),
      setPromoCode: (promoCode) => set({ promoCode }),
      setTable: (table) => set({ table }),
    }),
    {
      name: `cart:${config.restaurantSlug}`,
      version: 1,
      // No storage while rendering web pages on the server; the cart fills in on the client.
      storage: createJSONStorage(() => {
        if (Platform.OS === 'web' && typeof window === 'undefined') {
          throw new Error('No storage on the server.');
        }

        return AsyncStorage;
      }),
    },
  ),
);

/** Number of items and display total, for the cart bar. */
export function cartSummary(lines: CartLine[]): { count: number; totalCents: number } {
  return lines.reduce(
    (summary, line) => ({
      count: summary.count + line.quantity,
      totalCents: summary.totalCents + line.unitPriceCents * line.quantity,
    }),
    { count: 0, totalCents: 0 },
  );
}
