import { VariableContextProvider } from 'nativewind';
import { useMemo, type PropsWithChildren } from 'react';

import { useColorScheme } from '@/hooks/use-color-scheme';
import { useRestaurant } from '@/lib/api/restaurant';
import { brandVariables, DEFAULT_BRAND_COLOR } from '@/theme/brand';

/**
 * Applies the restaurant's brand colour from its settings, so the same code can serve
 * other restaurants. Until the settings load, global.css holds the demo's maroon.
 */
export function BrandTheme({ children }: PropsWithChildren) {
  const { data: restaurant } = useRestaurant();
  const scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const brandColor = restaurant?.brand_color ?? DEFAULT_BRAND_COLOR;

  const variables = useMemo(() => brandVariables(brandColor, scheme), [brandColor, scheme]);

  return <VariableContextProvider value={variables}>{children}</VariableContextProvider>;
}
