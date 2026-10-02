import { brand } from '@/lib/config';

/**
 * A website icon of the build's brand, served from public/brands/<brand>/: the home-screen
 * icons (icon-192.png, icon-512.png, icon-maskable-512.png) and apple-touch-icon.png.
 */
export function webIcon(file: string): string {
  return `/brands/${brand?.id ?? 'himalayan-momo-house'}/${file}`;
}
