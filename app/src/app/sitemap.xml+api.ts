import { fetchMenu } from '@/lib/api/menu';
import { siteOrigin } from '@/lib/page-meta';

/** The pages search engines should know about: the menu and each dish on it. */
export async function GET(request: Request): Promise<Response> {
  const origin = siteOrigin(request);
  const paths = ['/'];

  try {
    const menu = await fetchMenu();

    for (const category of menu.categories) {
      for (const item of category.items) {
        paths.push(`/item/${item.id}`);
      }
    }
  } catch {
    // The menu page alone, until the API answers again.
  }

  const urls = paths.map((path) => `  <url><loc>${escapeXml(`${origin}${path}`)}</loc></url>`).join('\n');
  const body = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`;

  return new Response(body, { headers: { 'Content-Type': 'application/xml; charset=utf-8', 'Cache-Control': 'public, max-age=3600' } });
}

function escapeXml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
