import type { PropsWithChildren } from 'react';

/**
 * iOS and Android: nothing to do. Pages are only rendered on the server for the web (see
 * server-storefront.web.tsx); the apps load the menu themselves.
 */
export function ServerStorefront({ children }: PropsWithChildren<{ path: string }>) {
  return children;
}
