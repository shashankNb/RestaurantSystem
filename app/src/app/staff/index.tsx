import { Redirect } from 'expo-router';
import type { Metadata } from 'expo-router/server';

import { privatePage, toMetadata } from '@/lib/page-meta';

/** Web: the page's title in the server's HTML. It stays out of search results. */
export function generateMetadata(): Metadata {
  return toMetadata(privatePage('Kitchen'));
}

/** /staff opens the order board (which sends anyone signed out to the sign-in). */
export default function StaffIndex() {
  return <Redirect href="/staff/orders" />;
}
