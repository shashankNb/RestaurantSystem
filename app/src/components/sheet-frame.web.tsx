import { useEffect, useEffectEvent, useRef, type PropsWithChildren } from 'react';
import { Pressable, View } from 'react-native';

/**
 * Web: the item screen as a dialog over the menu. A bottom sheet on phones, centred from
 * 640 px. Escape or a click outside closes it; Tab stays inside it while it's open, and
 * focus goes back where it was afterwards.
 */
export function SheetFrame({ children, onClose, labelledBy }: PropsWithChildren<{ onClose: () => void; labelledBy?: string }>) {
  const panelRef = useRef<View>(null);
  const close = useEffectEvent(onClose);

  useEffect(() => {
    const panel = panelRef.current as unknown as HTMLElement | null;
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    panel?.focus();

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
      } else if (event.key === 'Tab' && panel) {
        keepFocusInside(event, panel);
      }
    };

    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      previous?.focus();
    };
  }, []);

  return (
    <View className="flex-1 items-center justify-end sm:justify-center sm:p-6">
      <Pressable aria-hidden focusable={false} onPress={onClose} className="bg-scrim absolute inset-0 cursor-default" />
      <View
        ref={panelRef}
        role="dialog"
        aria-modal
        aria-labelledby={labelledBy}
        tabIndex={-1}
        className="bg-background max-h-[92%] w-full max-w-xl overflow-hidden rounded-t-xl outline-none sm:rounded-xl"
      >
        {children}
      </View>
    </View>
  );
}

/** The dialog's body takes its content's height, and scrolls once the dialog is full. */
export const sheetBodyStyle = { flexGrow: 0, flexShrink: 1 } as const;

const FOCUSABLE = 'a[href], button, input, textarea, select, [tabindex]:not([tabindex="-1"])';

function keepFocusInside(event: KeyboardEvent, panel: HTMLElement) {
  const focusable = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
    (element) => !element.hasAttribute('disabled') && element.getAttribute('aria-disabled') !== 'true',
  );
  const first = focusable[0];
  const last = focusable[focusable.length - 1];

  if (first === undefined || last === undefined) {
    event.preventDefault();

    return;
  }

  if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
