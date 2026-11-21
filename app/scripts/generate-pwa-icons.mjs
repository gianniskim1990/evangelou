// STAGE-08 round 11 (owner decision D26 part 1): the home-screen icons of the member app, generated from the
// bundled logo (src/preview/assets/logo-evaggelou-color.png). Only the wing mark (the left part of the logo) is
// used: the full word mark is unreadable at 192 px. The mark is drawn in brass on the roast background of the
// member card (the design tokens of src/preview/preview.css). The PNG files are committed under
// src/customer/pwa/icons/ and copied into the package (customer-app/pwa/) by scripts/package-club-plugin.mjs;
// run this script again only when the logo or the colours change, and commit the result.
//
//   icon-192.png, icon-512.png    purpose "any": the mark fills about 70 % of the square
//   icon-maskable-512.png         purpose "maskable": the mark stays inside the central 60 % (the safe circle
//                                 of radius 40 % holds it whatever mask the phone applies)
//   apple-touch-icon.png          180 x 180 for iOS (no transparency; iOS rounds the corners itself)
import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const appDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const logo = join(appDir, "src", "preview", "assets", "logo-evaggelou-color.png");
const outDir = join(appDir, "src", "customer", "pwa", "icons");
export const ROAST = "#1e1713";
export const BRASS = { r: 0xc8, g: 0xb2, b: 0x7c };
/** The wing mark: columns 0..107 of the 550 x 151 logo (the first run of opaque columns). */
const MARK = { left: 0, top: 0, width: 108, height: 151 };

async function brassMark() {
  const { data, info } = await sharp(logo).extract(MARK).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  for (let i = 0; i < data.length; i += 4) {
    data[i] = BRASS.r;
    data[i + 1] = BRASS.g;
    data[i + 2] = BRASS.b;
  }
  return sharp(data, { raw: { width: info.width, height: info.height, channels: 4 } }).trim().png().toBuffer();
}

async function icon(mark, size, fill, name) {
  const box = Math.round(size * fill);
  const scaled = await sharp(mark).resize({ width: box, height: box, fit: "inside" }).png().toBuffer();
  await sharp({ create: { width: size, height: size, channels: 3, background: ROAST } })
    .composite([{ input: scaled, gravity: "center" }])
    .removeAlpha()
    .png({ compressionLevel: 9 })
    .toFile(join(outDir, name));
}

mkdirSync(outDir, { recursive: true });
const mark = await brassMark();
await icon(mark, 192, 0.7, "icon-192.png");
await icon(mark, 512, 0.7, "icon-512.png");
await icon(mark, 512, 0.6, "icon-maskable-512.png");
await icon(mark, 180, 0.7, "apple-touch-icon.png");
console.log(`PWA icons written to ${outDir}`);
