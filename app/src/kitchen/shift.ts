import { create } from 'zustand';

/**
 * Whether a shift is under way on this device: alerts on, screen kept awake. Kept outside
 * the kitchen's components so a remount (after a redirect, say) doesn't end it. In memory
 * only: after a reload staff start again, which browsers need anyway to allow sound.
 */
export const useShift = create<{ started: boolean; setStarted: (started: boolean) => void }>()((set) => ({
  started: false,
  setStarted: (started) => set({ started }),
}));
