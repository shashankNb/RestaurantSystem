import { siteOrigin } from '@/lib/page-meta';

/** For search engines: everything but the kitchen screens, and where the sitemap is. */
export function GET(request: Request): Response {
  const body = ['User-agent: *', 'Disallow: /staff', '', `Sitemap: ${siteOrigin(request)}/sitemap.xml`, ''].join('\n');

  return new Response(body, { headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } });
}
