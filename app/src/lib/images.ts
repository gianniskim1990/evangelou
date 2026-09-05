import { slugify } from "./slug";

/**
 * Image URL for a product: an admin-uploaded photo (served from Redis via
 * api/images/product/:slug, cache-busted by upload timestamp) takes
 * priority over the pre-downloaded Pexels stock photo baked into
 * public/images at build time (see scripts/fetch-stock-images.mjs).
 * `customImages` comes from useMenu().productImages.
 */
export function productImagePath(name: string, customImages?: Record<string, string>): string {
  const slug = slugify(name);
  const version = customImages?.[slug];
  if (version) return `/api/images/product/${slug}?v=${encodeURIComponent(version)}`;
  return `/images/products/${slug}.webp`;
}

export function categoryImagePath(categoryId: string, customImages?: Record<string, string>): string {
  const slug = slugify(categoryId);
  const version = customImages?.[slug];
  if (version) return `/api/images/category/${slug}?v=${encodeURIComponent(version)}`;
  return `/images/categories/${slug}.webp`;
}
