import Constants from 'expo-constants';
import { ScrollViewStyleReset, useServerDocumentContext } from 'expo-router/html';
import { Children, cloneElement, isValidElement, type PropsWithChildren, type ReactNode } from 'react';

import { webIcon } from '@/lib/web-icons';
import { brandColors, DEFAULT_BRAND_COLOR, PAGE } from '@/theme/brand';

/**
 * The HTML document around every web page, rendered on the web server for each request.
 * Expo's nodes (the page's metadata, styles and scripts) must all be included, or the page
 * won't hydrate. The page colour is set here too, so there's no white flash before the
 * app's styles load.
 */
export default function Root({ children }: PropsWithChildren) {
  const { htmlAttributes, bodyAttributes, headNodes, bodyNodes } = useServerDocumentContext();

  return (
    <html lang="en-AU" {...htmlAttributes}>
      <head>
        <meta charSet="utf-8" />
        <meta httpEquiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="color-scheme" content="light dark" />
        {/* The browser's toolbar in the brand colour, like the band at the top of the menu. */}
        <meta name="theme-color" media="(prefers-color-scheme: light)" content={brandColors(DEFAULT_BRAND_COLOR, 'light').primary} />
        <meta name="theme-color" media="(prefers-color-scheme: dark)" content={brandColors(DEFAULT_BRAND_COLOR, 'dark').primary} />
        {/* Installing to the home screen: src/app/manifest.webmanifest+api.ts and the brand's icons. */}
        <link rel="manifest" href="/manifest.webmanifest" />
        <link rel="apple-touch-icon" href={webIcon('apple-touch-icon.png')} />
        <meta name="mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-title" content={Constants.expoConfig?.web?.shortName ?? Constants.expoConfig?.name} />
        <ScrollViewStyleReset />
        <style dangerouslySetInnerHTML={{ __html: pageBackground }} />
        {adoptableByHead(headNodes)}
      </head>
      <body {...bodyAttributes}>
        {children}
        {bodyNodes}
      </body>
    </html>
  );
}

/**
 * The page's <meta> and <link> tags from `generateMetadata`, marked as expo-router/head's
 * own (data-rh), so <PageHead> takes them over after hydration rather than adding copies.
 */
function adoptableByHead(nodes: ReactNode): ReactNode {
  return Children.map(nodes, (node) =>
    isValidElement(node) && (node.type === 'meta' || node.type === 'link') && String(node.key).includes('metadata-')
      ? cloneElement(node, { 'data-rh': 'true' } as Record<string, string>)
      : node,
  );
}

const pageBackground = `
body { background-color: ${PAGE.light}; }
@media (prefers-color-scheme: dark) {
  body { background-color: ${PAGE.dark}; }
}`;
