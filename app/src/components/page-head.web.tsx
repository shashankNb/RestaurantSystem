import Head from 'expo-router/head';

import { headTags, type PageMeta } from '@/lib/page-meta';

/**
 * The page's title, description and share tags while it's on screen. The web server wrote
 * the same tags into the HTML (the page's `generateMetadata`); these take them over after
 * hydration and keep them right as people move around the app.
 */
export function PageHead({ page }: { page: PageMeta }) {
  return (
    <Head>
      <title>{page.title}</title>
      {headTags(page).map((tag) =>
        tag.tag === 'link' ? (
          <link key={`${tag.rel}:${tag.href}`} rel={tag.rel} href={tag.href} />
        ) : (
          <meta key={tag.key} {...{ [tag.attribute]: tag.key }} content={tag.content} />
        ),
      )}
    </Head>
  );
}
