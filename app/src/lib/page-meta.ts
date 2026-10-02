import Constants from 'expo-constants';
import type { Metadata } from 'expo-router/server';

import type { MenuItem, Restaurant } from '@/lib/api/schemas';
import { config } from '@/lib/config';
import { formatMoney } from '@/lib/money';

/**
 * A page's title and share details. Each page describes itself once; the web server writes
 * the tags into the HTML (`generateMetadata`) and <PageHead> keeps them current as people
 * move around the app, from the same list of tags.
 */
export interface PageMeta {
  title: string;
  description?: string;
  /** The page's address, e.g. "/item/3", for the canonical link and og:url. */
  path?: string;
  image?: { url: string; alt: string } | null;
  /** Kept out of search results: private pages (cart, checkout, orders, account, the kitchen) and dishes that are gone. */
  noindex?: boolean;
}

/** The build's name, which is the restaurant's (APP_NAME in app.config.ts). */
export const SITE_NAME = Constants.expoConfig?.name ?? '';

/** Search results cut descriptions off at about this length. */
const DESCRIPTION_MAX = 160;

/** An address on the public website (EXPO_PUBLIC_WEB_URL); undefined when that isn't set. */
export function absoluteUrl(path: string): string | undefined {
  return config.webUrl ? `${config.webUrl.replace(/\/+$/, '')}${path}` : undefined;
}

/** The public website's origin, or else the one this request came in on (API routes). */
export function siteOrigin(request: Request): string {
  return config.webUrl ? config.webUrl.replace(/\/+$/, '') : new URL(request.url).origin;
}

export type HeadTag =
  | { tag: 'meta'; attribute: 'name' | 'property'; key: string; content: string }
  | { tag: 'link'; rel: string; href: string };

/**
 * The <meta> and <link> tags for a page, in the form the server's metadata renderer writes
 * them (see toMetadata). <PageHead> renders the same list, so the browser adopts the
 * server's tags instead of adding a second copy.
 */
export function headTags(page: PageMeta): HeadTag[] {
  const tags: HeadTag[] = [];
  const name = (key: string, content: string | undefined) => {
    if (content) {
      tags.push({ tag: 'meta', attribute: 'name', key, content });
    }
  };
  const property = (key: string, content: string | undefined) => {
    if (content) {
      tags.push({ tag: 'meta', attribute: 'property', key, content });
    }
  };
  const url = page.path === undefined || page.noindex ? undefined : absoluteUrl(page.path);

  name('description', page.description);

  if (page.noindex) {
    name('robots', 'noindex');

    return tags;
  }

  if (url) {
    tags.push({ tag: 'link', rel: 'canonical', href: url });
  }

  property('og:title', page.title);
  property('og:description', page.description);
  property('og:url', url);
  property('og:site_name', SITE_NAME);
  property('og:locale', 'en_AU');
  property('og:type', 'website');
  property('og:image', page.image?.url);
  property('og:image:alt', page.image?.alt);
  name('twitter:card', page.image ? 'summary_large_image' : 'summary');

  return tags;
}

/** The same page for the web server's `generateMetadata`. */
export function toMetadata(page: PageMeta): Metadata {
  if (page.noindex) {
    return { title: page.title, description: page.description, robots: 'noindex' };
  }

  const url = page.path === undefined ? undefined : absoluteUrl(page.path);

  return {
    title: page.title,
    description: page.description,
    alternates: url ? { canonical: url } : undefined,
    openGraph: {
      title: page.title,
      description: page.description,
      url,
      siteName: SITE_NAME || undefined,
      locale: 'en_AU',
      type: 'website',
      images: page.image ? [{ url: page.image.url, alt: page.image.alt }] : undefined,
    },
    twitter: { card: page.image ? 'summary_large_image' : 'summary' },
  };
}

/** A private page: its name, then the restaurant's. */
export function privatePage(title: string): PageMeta {
  return { title: SITE_NAME ? `${title} · ${SITE_NAME}` : title, noindex: true };
}

/** The menu, the home page. */
export function menuPage(restaurant: Restaurant | null | undefined): PageMeta {
  const name = restaurant?.name ?? SITE_NAME;
  const image = restaurant?.cover_image_url ?? restaurant?.logo_url;

  return {
    title: `${name} · Order online`,
    description: restaurant ? clip(restaurant.description ?? defaultDescription(restaurant)) : undefined,
    path: '/',
    image: image ? { url: image, alt: name } : null,
  };
}

/** One dish. Without it (gone from the menu, or the menu didn't load), the menu's title, unindexed. */
export function itemPage(restaurant: Restaurant | null | undefined, item: MenuItem | undefined): PageMeta {
  if (item === undefined) {
    return { title: menuPage(restaurant).title, noindex: true };
  }

  const name = restaurant?.name ?? SITE_NAME;
  const price = restaurant ? formatMoney(item.price_cents, restaurant.currency) : null;
  const summary = [item.description, price].filter(Boolean).join(' ');
  const image = item.image_url ?? restaurant?.cover_image_url ?? restaurant?.logo_url;

  return {
    title: `${item.name} · ${name}`,
    description: clip(`${summary ? `${summary} ` : ''}Order online from ${name}.`),
    path: `/item/${item.id}`,
    image: image ? { url: image, alt: item.image_url ? item.name : name } : null,
  };
}

function defaultDescription(restaurant: Restaurant): string {
  const { pickup, delivery } = restaurant.fulfilment;
  const ways = [pickup.enabled ? 'pickup' : null, delivery.enabled ? 'delivery' : null].filter(Boolean).join(' or ');
  const suburb = restaurant.address?.suburb;

  return `Order ${ways || 'online'} from ${restaurant.name}${suburb ? ` in ${suburb}` : ''}. See the menu, choose a time and pay online.`;
}

function clip(text: string): string {
  const trimmed = text.trim();

  return trimmed.length <= DESCRIPTION_MAX ? trimmed : `${trimmed.slice(0, DESCRIPTION_MAX - 1).trimEnd()}…`;
}
