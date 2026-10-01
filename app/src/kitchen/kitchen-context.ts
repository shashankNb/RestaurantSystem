import { createContext, useContext } from 'react';

/** The shift, shared by the kitchen screens. Its alerts and keep-awake run in their layout. */
export interface Kitchen {
  /** Alerts are on and the screen stays awake. */
  started: boolean;
  startShift: () => void;
  endShift: () => void;
}

export const KitchenContext = createContext<Kitchen | null>(null);

export function useKitchen(): Kitchen {
  const kitchen = useContext(KitchenContext);

  if (kitchen === null) {
    throw new Error('useKitchen() is for screens inside src/app/staff/(kitchen).');
  }

  return kitchen;
}
