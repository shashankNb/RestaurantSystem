import AsyncStorage from '@react-native-async-storage/async-storage';
import { Platform } from 'react-native';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import { config } from '@/lib/config';

export interface RecentOrder {
  publicId: string;
  trackingToken: string;
  createdAt: string;
}

interface RecentOrdersState {
  orders: RecentOrder[];
  remember: (publicId: string, trackingToken: string) => void;
}

const KEEP = 20;

/**
 * Orders placed on this device with their tracking tokens, so a guest can reopen them and
 * a notification can open the right order. Newest first, the last 20.
 */
export const useRecentOrders = create<RecentOrdersState>()(
  persist(
    (set) => ({
      orders: [],
      remember: (publicId, trackingToken) =>
        set((state) => ({
          orders: [
            { publicId, trackingToken, createdAt: new Date().toISOString() },
            ...state.orders.filter((order) => order.publicId !== publicId),
          ].slice(0, KEEP),
        })),
    }),
    {
      name: `recent-orders:${config.restaurantSlug}`,
      version: 1,
      storage: createJSONStorage(() => {
        if (Platform.OS === 'web' && typeof window === 'undefined') {
          throw new Error('No storage on the server.');
        }

        return AsyncStorage;
      }),
    },
  ),
);

/** Placed in the last 12 hours: old enough orders aren't "current", whatever their status. */
export function isRecent(order: RecentOrder, now: number = Date.now()): boolean {
  return now - new Date(order.createdAt).getTime() < 12 * 60 * 60 * 1000;
}
