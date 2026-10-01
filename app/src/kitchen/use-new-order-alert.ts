import { setAudioModeAsync, useAudioPlayer } from 'expo-audio';
import { useEffect, useEffectEvent, useRef } from 'react';
import { AccessibilityInfo } from 'react-native';

import chime from '@/assets/sounds/new-order.wav';

/** How often the alert repeats while an order is waiting to be accepted. */
const REPEAT_MS = 10_000;

/**
 * The kitchen's new-order alert. While `active` (the shift has started), it plays a chime as
 * soon as a new order appears and every 10 seconds after, until every new order has been
 * accepted or rejected. Returns `ring`, which plays it once: "Start shift" calls it, because
 * browsers only allow sound after someone has interacted with the page.
 */
export function useNewOrderAlert({ active, newOrderIds }: { active: boolean; newOrderIds: string[] }) {
  const player = useAudioPlayer(chime);
  const heard = useRef(new Set<string>());
  const key = newOrderIds.join(',');
  const waiting = key !== '';

  const ring = useEffectEvent(() => {
    void player.seekTo(0);
    player.play();
  });

  // A new order rings straight away.
  useEffect(() => {
    const ids = key === '' ? [] : key.split(',');
    const fresh = ids.filter((id) => !heard.current.has(id));
    ids.forEach((id) => heard.current.add(id));

    if (active && fresh.length > 0) {
      ring();
      AccessibilityInfo.announceForAccessibility(fresh.length === 1 ? 'New order' : `${fresh.length} new orders`);
    }
  }, [key, active]);

  // ...and the alert repeats until no order is waiting.
  useEffect(() => {
    if (!active || !waiting) {
      return;
    }

    const timer = setInterval(() => ring(), REPEAT_MS);

    return () => clearInterval(timer);
  }, [active, waiting]);

  return {
    /** Plays the alert once, and lets it play on a silenced iPhone or iPad. */
    ring: () => {
      void setAudioModeAsync({ playsInSilentMode: true, interruptionMode: 'duckOthers' }).catch(() => undefined);
      void player.seekTo(0);
      player.play();
    },
  };
}
