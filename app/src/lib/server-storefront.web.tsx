import { useLoaderData, usePathname } from 'expo-router';
import { useState, useSyncExternalStore, type PropsWithChildren } from 'react';

import { StorefrontContext, type StorefrontLoaderData } from '@/lib/storefront-context';

const subscribe = () => () => {};

/**
 * Wraps a public page that has a `loader`. When the web server rendered the page, its
 * queries start from the data the loader returned, so the server's HTML shows the menu and
 * the browser hydrates the same thing.
 *
 * Only the page the browser asked for (`path`, e.g. "/item/3") has that data, and only
 * while it's rendered on the server or hydrated from the server's HTML. Pages opened later
 * in the app, or the menu underneath a dish, use the query cache as usual; calling
 * useLoaderData there would fetch the loader from the server again on every visit.
 */
export function ServerStorefront({ path, children }: PropsWithChildren<{ path: string }>) {
  const pathname = usePathname();
  // React uses the server snapshot on the server and while hydrating, and the client one
  // for anything rendered afresh in the browser, however late the page's code arrives.
  const fromServer = useSyncExternalStore(
    subscribe,
    () => false,
    () => true,
  );
  // Decided once, so the page keeps its state after hydration and when the address changes.
  const [rendered] = useState(fromServer && pathname === path);

  return rendered ? <LoadedStorefront>{children}</LoadedStorefront> : children;
}

function LoadedStorefront({ children }: PropsWithChildren) {
  const { storefront } = useLoaderData<() => StorefrontLoaderData>();

  return <StorefrontContext value={storefront}>{children}</StorefrontContext>;
}
