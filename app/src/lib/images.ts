import { slugify } from "./slug";

/**
 * Local, pre-downloaded stock photo paths (see scripts/fetch-stock-images.mjs).
 * No network calls happen at runtime — these files are committed to
 * public/images and must exist before deploy.
 */
export function productImagePath(name: string): string {
  return `/images/products/${slugify(name)}.webp`;
}

export function categoryImagePath(categoryId: string): string {
  return `/images/categories/${slugify(categoryId)}.webp`;
}
