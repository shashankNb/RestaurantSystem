import type { Menu, Restaurant } from '@/lib/api/schemas';
import { absoluteUrl } from '@/lib/page-meta';

/**
 * schema.org data about the restaurant and its menu (JSON-LD), for search engines: the
 * address, phone and opening hours, and each dish with its price, availability and diets.
 * https://schema.org/Restaurant and https://schema.org/Menu
 */
export function restaurantStructuredData(restaurant: Restaurant, menu: Menu | undefined): Record<string, unknown> {
  const home = absoluteUrl('/');
  const id = (fragment: string) => (home ? `${home}#${fragment}` : `#${fragment}`);
  const { address } = restaurant;
  const images = [restaurant.cover_image_url, restaurant.logo_url].filter((url): url is string => Boolean(url));

  const place = {
    '@type': 'Restaurant',
    '@id': id('restaurant'),
    name: restaurant.name,
    description: restaurant.description ?? undefined,
    url: home,
    telephone: restaurant.phone ?? undefined,
    email: restaurant.email ?? undefined,
    image: images.length > 0 ? images : undefined,
    logo: restaurant.logo_url ?? undefined,
    address: address
      ? {
          '@type': 'PostalAddress',
          streetAddress: [address.line1, address.line2].filter(Boolean).join(', ') || undefined,
          addressLocality: address.suburb ?? undefined,
          addressRegion: address.state ?? undefined,
          postalCode: address.postcode ?? undefined,
          addressCountry: address.country ?? 'AU',
        }
      : undefined,
    currenciesAccepted: restaurant.currency,
    acceptsReservations: false,
    openingHoursSpecification: restaurant.opening_hours.map((hours) => ({
      '@type': 'OpeningHoursSpecification',
      dayOfWeek: `https://schema.org/${DAYS[hours.day_of_week]}`,
      opens: hours.opens_at,
      closes: hours.closes_at,
    })),
    // Holidays and other one-off changes; closed all day is 00:00 to 00:00.
    specialOpeningHoursSpecification: restaurant.special_hours.map((hours) => ({
      '@type': 'OpeningHoursSpecification',
      validFrom: hours.date,
      validThrough: hours.date,
      opens: hours.is_closed ? '00:00' : (hours.opens_at ?? undefined),
      closes: hours.is_closed ? '00:00' : (hours.closes_at ?? undefined),
    })),
    hasMenu: menu ? { '@id': id('menu') } : undefined,
  };

  const graph: Record<string, unknown>[] = [place];

  if (menu) {
    graph.push({
      '@type': 'Menu',
      '@id': id('menu'),
      name: `${restaurant.name} menu`,
      url: home,
      inLanguage: 'en-AU',
      hasMenuSection: menu.categories
        .filter((category) => category.items.length > 0)
        .map((category) => ({
          '@type': 'MenuSection',
          name: category.name,
          description: category.description ?? undefined,
          hasMenuItem: category.items.map((item) => ({
            '@type': 'MenuItem',
            name: item.name,
            description: item.description ?? undefined,
            image: item.image_url ?? undefined,
            url: absoluteUrl(`/item/${item.id}`),
            offers: {
              '@type': 'Offer',
              price: (item.price_cents / 100).toFixed(2),
              priceCurrency: restaurant.currency,
              availability: `https://schema.org/${item.is_available ? 'InStock' : 'OutOfStock'}`,
            },
            suitableForDiet: dietsOf(item.dietary_tags.map((tag) => tag.value)),
          })),
        })),
    });
  }

  return { '@context': 'https://schema.org', '@graph': graph };
}

/** JSON for inside a <script> element: "</script>" in a dish's name mustn't end it. */
export function scriptJson(data: unknown): string {
  return JSON.stringify(data).replace(/</g, '\\u003c');
}

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const;

/** The menu's dietary tags as schema.org diets. Dairy free is the nearest, low lactose. */
const DIETS: Record<string, string> = {
  vegetarian: 'VegetarianDiet',
  vegan: 'VeganDiet',
  gluten_free: 'GlutenFreeDiet',
  dairy_free: 'LowLactoseDiet',
  halal: 'HalalDiet',
};

function dietsOf(tags: string[]): string[] | undefined {
  const diets = tags.flatMap((tag) => (DIETS[tag] ? [`https://schema.org/${DIETS[tag]}`] : []));

  return diets.length > 0 ? diets : undefined;
}
