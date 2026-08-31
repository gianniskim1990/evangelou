#!/usr/bin/env node
// One-time (re-runnable) importer that fills public/images/{products,categories}
// with real stock photos from the Pexels API, so the shipped site never calls
// Pexels at runtime — every image is downloaded, resized and converted to
// WebP locally, ahead of commit/deploy.
//
// Usage:
//   PEXELS_API_KEY=... npm run images:stock
//   npm run images:stock -- --force   (re-download everything)
//
// The API key is read exclusively from a local environment variable
// (or app/.env.local, which is git-ignored). It is never written into
// frontend code, never prefixed VITE_, and never committed.

import { createRequire } from "node:module";
import { mkdir, readFile, writeFile, unlink, access } from "node:fs/promises";
import path from "node:path";
import os from "node:os";
import { fileURLToPath, pathToFileURL } from "node:url";
import sharp from "sharp";

const require = createRequire(import.meta.url);
const esbuild = require("esbuild");

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const FORCE = process.argv.includes("--force");
const PRODUCTS_DIR = path.join(ROOT, "public/images/products");
const CATEGORIES_DIR = path.join(ROOT, "public/images/categories");
const CREDITS_PATH = path.join(ROOT, "public/stock-image-credits.json");
const PEXELS_SEARCH_URL = "https://api.pexels.com/v1/search";

// ---------------------------------------------------------------------------
// English search queries, built from the Greek category names by hand (the
// same kind of mapping the brief asked for — e.g. "πατάτες" → "french fries").
// Product queries reuse their category's query, sharpened with any flavour
// word recognised in the Greek product name.
// ---------------------------------------------------------------------------
const CATEGORY_QUERIES = {
  profiterole: "profiterole dessert chocolate",
  profiterole_icecream: "profiterole ice cream dessert",
  profiterole_chilly: "chilled ice cream dessert cup",
  profiterole_dubai: "dubai chocolate pistachio dessert",
  profiterole_lotus: "lotus biscoff dessert",
  icecream: "ice cream tub dessert",
  icecream_ball: "ice cream scoop bowl",
  icecream_mini: "mini ice cream cup",
  cones: "ice cream cone",
  cakes: "celebration cake slice bakery",
  icecream_cakes: "ice cream cake dessert",
  cupcakes: "cupcake bakery",
  sweets_individual: "bakery pastry dessert plate",
  tarts: "fruit tart pastry",
  cheesecake: "cheesecake slice",
  millefeuille: "mille-feuille napoleon pastry",
  creams: "custard cream dessert cup",
  tray_sweets: "baklava tray pastry",
  syrup_sweets: "greek syrup semolina cake",
  touloumpakia: "loukoumades honey balls dessert",
  chocolates_cat: "chocolate bar",
  pralines: "chocolate pralines box",
  choc_syringes: "chocolate candy stick",
  marshmallows: "marshmallow chocolate",
  cookies: "butter cookies bakery",
  treats: "greek christmas cookies",
  diabetic: "sugar-free cake slice",
  coffees: "coffee cup espresso",
  milkshakes: "milkshake glass",
  hot_drinks: "hot chocolate cup",
  juices: "fresh juice glass",
  sodas: "soft drink glass ice",
  easter: "easter bread tsoureki",
  halva: "halva dessert sweet",
};
const DEFAULT_QUERY = "greek pastry dessert";

const FLAVOR_KEYWORDS = {
  "βανίλια": "vanilla",
  "σοκολάτα": "chocolate",
  "φράουλα": "strawberry",
  "πιστάτσιο": "pistachio",
  "λεμόνι": "lemon",
  "καραμελέ": "caramel",
  "καραμέλα": "caramel",
  "φουντούκι": "hazelnut",
  "αμυγδάλου": "almond",
  "αμύγδαλο": "almond",
  "γάλακτος": "milk chocolate",
  "υγείας": "dark chocolate",
  "λικέρ": "liqueur",
  "ταχίνι": "tahini",
  "σιμιγδαλένιος": "semolina",
  "κεράσι": "cherry",
  "μπανάνα": "banana",
  "πορτοκάλι": "orange",
  "λευκής": "white chocolate",
  "λευκή": "white chocolate",
  "red velvet": "red velvet",
  "oreo": "oreo",
  "lotus": "biscoff lotus",
  "dubai": "dubai pistachio",
  "chilly": "chilled ice cream",
  "espresso": "espresso",
  "freddo": "iced coffee",
  "καπουτσίνο": "cappuccino",
};

function categoryQuery(categoryId) {
  return CATEGORY_QUERIES[categoryId] ?? DEFAULT_QUERY;
}

function productQuery(name, categoryId) {
  const base = categoryQuery(categoryId);
  const lower = name.toLowerCase();
  const flavors = [];
  for (const [gr, en] of Object.entries(FLAVOR_KEYWORDS)) {
    if (lower.includes(gr) && !flavors.includes(en)) flavors.push(en);
  }
  return flavors.length ? `${flavors.join(" ")} ${base}` : base;
}

// ---------------------------------------------------------------------------
// Load the app's own TypeScript modules (menu data + the slug() used by the
// frontend) at run time, so filenames always match what the UI requests and
// nothing is duplicated by hand.
// ---------------------------------------------------------------------------
async function loadTsModule(relPath) {
  const absPath = path.join(ROOT, relPath);
  const source = await readFile(absPath, "utf8");
  const { code } = esbuild.transformSync(source, { loader: "ts", format: "esm" });
  const tmpFile = path.join(os.tmpdir(), `evaggelou-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
  await writeFile(tmpFile, code, "utf8");
  try {
    return await import(pathToFileURL(tmpFile).href);
  } finally {
    await unlink(tmpFile).catch(() => {});
  }
}

async function loadApiKey() {
  if (process.env.PEXELS_API_KEY) return process.env.PEXELS_API_KEY.trim();
  try {
    const text = await readFile(path.join(ROOT, ".env.local"), "utf8");
    for (const line of text.split("\n")) {
      const m = line.match(/^\s*PEXELS_API_KEY\s*=\s*(.+?)\s*$/);
      if (m) return m[1].replace(/^["']|["']$/g, "").trim();
    }
  } catch {
    // no .env.local — fine, we'll report the missing key below.
  }
  return null;
}

async function fileExists(p) {
  try {
    await access(p);
    return true;
  } catch {
    return false;
  }
}

async function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function searchPexels(query, orientation, apiKey) {
  const url = new URL(PEXELS_SEARCH_URL);
  url.searchParams.set("query", query);
  url.searchParams.set("per_page", "3");
  url.searchParams.set("orientation", orientation);

  for (let attempt = 1; attempt <= 2; attempt++) {
    const res = await fetch(url, { headers: { Authorization: apiKey } });
    if (res.ok) {
      const data = await res.json();
      return data.photos?.[0] ?? null;
    }
    if (res.status === 429 && attempt === 1) {
      await sleep(2000);
      continue;
    }
    throw new Error(`Pexels ${res.status} for "${query}"`);
  }
  return null;
}

async function downloadToWebp(photoSrcUrl, destPath, { width, height, quality }) {
  const res = await fetch(photoSrcUrl);
  if (!res.ok) throw new Error(`Failed to download image (${res.status})`);
  const buf = Buffer.from(await res.arrayBuffer());
  await mkdir(path.dirname(destPath), { recursive: true });
  await sharp(buf)
    .resize(width, height, { fit: "cover" })
    .webp({ quality })
    .toFile(destPath);
}

async function copyWebp(srcPath, destPath) {
  await mkdir(path.dirname(destPath), { recursive: true });
  const buf = await readFile(srcPath);
  await writeFile(destPath, buf);
}

async function loadExistingCredits() {
  try {
    const text = await readFile(CREDITS_PATH, "utf8");
    return JSON.parse(text);
  } catch {
    return [];
  }
}

// ---------------------------------------------------------------------------

async function main() {
  const apiKey = await loadApiKey();
  if (!apiKey) {
    console.error(
      "Missing PEXELS_API_KEY. Set it as a local environment variable, e.g.\n" +
        "  PEXELS_API_KEY=your_key npm run images:stock\n" +
        "or create app/.env.local (git-ignored) with:\n" +
        "  PEXELS_API_KEY=your_key",
    );
    process.exitCode = 1;
    return;
  }

  const { slugify } = await loadTsModule("src/lib/slug.ts");
  const menu = await loadTsModule("src/data/menu.ts");
  const { categoryNames, products, offers, popular } = menu;

  const categories = Object.entries(categoryNames).map(([id, name]) => ({ id, name }));

  const productMap = new Map(); // slug -> { name, categoryId }
  for (const [categoryId, list] of Object.entries(products)) {
    for (const p of list) {
      const slug = slugify(p.name);
      if (!productMap.has(slug)) productMap.set(slug, { name: p.name, categoryId });
    }
  }
  for (const o of offers) {
    const slug = slugify(o.name);
    if (!productMap.has(slug)) productMap.set(slug, { name: o.name, categoryId: o.cat });
  }
  for (const p of popular) {
    const slug = slugify(p.name);
    if (!productMap.has(slug)) productMap.set(slug, { name: p.name, categoryId: p.cat });
  }

  const existingCredits = await loadExistingCredits();
  const creditsBySlug = new Map(existingCredits.map((c) => [`${c.kind}:${c.slug}`, c]));

  let fetched = 0;
  let skipped = 0;
  let fallbackCount = 0;

  console.log(`Categories: ${categories.length}  Products: ${productMap.size}`);

  // --- categories (landscape) ----------------------------------------
  for (const { id, name } of categories) {
    const slug = slugify(id);
    const dest = path.join(CATEGORIES_DIR, `${slug}.webp`);
    const key = `category:${slug}`;

    if (!FORCE && (await fileExists(dest))) {
      skipped++;
      continue;
    }

    const query = categoryQuery(id);
    try {
      const photo = await searchPexels(query, "landscape", apiKey);
      if (!photo) {
        console.warn(`No Pexels result for category "${name}" (query: "${query}")`);
        continue;
      }
      const src = photo.src.large2x || photo.src.large || photo.src.original;
      await downloadToWebp(src, dest, { width: 800, height: 450, quality: 68 });
      creditsBySlug.set(key, {
        kind: "category",
        slug,
        name,
        query,
        pexelsId: photo.id,
        photographer: photo.photographer,
        photographerUrl: photo.photographer_url,
        pexelsUrl: photo.url,
      });
      fetched++;
      console.log(`✓ category ${slug} ← "${query}"`);
    } catch (err) {
      console.warn(`✗ category ${slug}: ${err.message}`);
    }
    await sleep(200);
  }

  // --- products (square) ----------------------------------------------
  for (const [slug, { name, categoryId }] of productMap) {
    const dest = path.join(PRODUCTS_DIR, `${slug}.webp`);

    if (!FORCE && (await fileExists(dest))) {
      skipped++;
      continue;
    }

    const query = productQuery(name, categoryId);
    try {
      const photo = await searchPexels(query, "square", apiKey);
      if (photo) {
        const src = photo.src.large2x || photo.src.large || photo.src.original;
        await downloadToWebp(src, dest, { width: 480, height: 480, quality: 72 });
        creditsBySlug.set(`product:${slug}`, {
          kind: "product",
          slug,
          name,
          query,
          pexelsId: photo.id,
          photographer: photo.photographer,
          photographerUrl: photo.photographer_url,
          pexelsUrl: photo.url,
        });
        fetched++;
        console.log(`✓ product ${slug} ← "${query}"`);
      } else {
        // Fallback: reuse the category's photo so the product still gets a
        // relevant image instead of nothing.
        const catSlug = slugify(categoryId);
        const catImage = path.join(CATEGORIES_DIR, `${catSlug}.webp`);
        if (await fileExists(catImage)) {
          await copyWebp(catImage, dest);
          const catCredit = creditsBySlug.get(`category:${catSlug}`);
          creditsBySlug.set(`product:${slug}`, {
            kind: "product",
            slug,
            name,
            query,
            fallbackOfCategory: catSlug,
            pexelsId: catCredit?.pexelsId ?? null,
            photographer: catCredit?.photographer ?? null,
            photographerUrl: catCredit?.photographerUrl ?? null,
            pexelsUrl: catCredit?.pexelsUrl ?? null,
          });
          fallbackCount++;
          console.log(`↳ product ${slug}: no result, fell back to category "${catSlug}" image`);
        } else {
          console.warn(`✗ product ${slug}: no Pexels result and no category fallback (query: "${query}")`);
        }
      }
    } catch (err) {
      console.warn(`✗ product ${slug}: ${err.message}`);
    }
    await sleep(200);
  }

  const credits = [...creditsBySlug.values()].sort((a, b) =>
    a.kind === b.kind ? a.slug.localeCompare(b.slug) : a.kind.localeCompare(b.kind),
  );
  await writeFile(CREDITS_PATH, JSON.stringify(credits, null, 2) + "\n", "utf8");

  console.log(
    `\nDone. Fetched ${fetched} new image(s), ${fallbackCount} category fallback(s), skipped ${skipped} existing. ` +
      `Credits written to public/stock-image-credits.json.`,
  );
}

main();
