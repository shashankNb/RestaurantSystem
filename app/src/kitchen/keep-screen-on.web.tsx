import { useEffect } from 'react';

/**
 * Web: keeps the screen on while mounted (during a shift), with the Screen Wake Lock API.
 * Browsers drop the lock whenever the tab is hidden, so it's taken again each time the tab
 * comes back. Browsers without the API just let the screen sleep.
 */
export function KeepScreenOn() {
  useEffect(() => {
    if (!('wakeLock' in navigator)) {
      return;
    }

    let lock: WakeLockSentinel | null = null;
    let stopped = false;

    const request = async () => {
      try {
        const next = await navigator.wakeLock.request('screen');

        if (stopped) {
          void next.release();
        } else {
          lock = next;
        }
      } catch {
        // Refused (battery saver, or the tab isn't visible): try again when it is.
      }
    };

    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        void request();
      }
    };

    void request();
    document.addEventListener('visibilitychange', onVisibilityChange);

    return () => {
      stopped = true;
      document.removeEventListener('visibilitychange', onVisibilityChange);
      void lock?.release();
    };
  }, []);

  return null;
}
