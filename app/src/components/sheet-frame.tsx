import type { PropsWithChildren } from 'react';
import { View } from 'react-native';

/**
 * iOS and Android: the item screen is presented as a native bottom sheet (see the root
 * layout), so this is just its background. sheet-frame.web.tsx draws a dialog instead.
 */
export function SheetFrame({ children }: PropsWithChildren<{ onClose: () => void; labelledBy?: string }>) {
  return <View className="bg-background flex-1">{children}</View>;
}

/** The sheet's scrolling body fills the sheet. */
export const sheetBodyStyle = { flex: 1 } as const;
