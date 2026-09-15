# Profiterole configurator — photo asset spec

This folder is where real product photography for "Φτιάξε το δικό σου
προφιτερόλ" (`app/src/screens/Configurator.tsx`) goes once it's shot/generated.
**Nothing in here is a placeholder photo** — these folders are intentionally
empty (only `.gitkeep` files) until real Evangelou photography is supplied.
Until then, the app renders the existing procedural CSS/SVG illustration and
looks and behaves exactly as it does today — see "How the fallback works"
below.

Do not add stock photos, AI-generated mockups, or anything not actually shot
of an Evangelou product to these folders "to fill them in." An empty folder
here means the photo pipeline is ready and waiting, not broken.

## How the fallback works

`app/src/configurator/ProfiterolePreview.tsx` probes `base/profiterole-base.webp`
on mount. If it fails to load, the whole preview renders the original
procedural illustration (gradients/SVG), unchanged. If it loads, the preview
switches to photo mode: the base photo plus whichever sauce/topping overlays
below *also* happen to be present — each one is probed independently and
simply omits itself if its file is missing, so the asset set can be filled in
gradually (e.g. ship the base + 2 chocolates first) without breaking anything
that isn't ready yet.

This means: **adding `base/profiterole-base.webp` alone is enough to flip the
whole preview into photo mode**, even with zero sauce/topping photos present
— so add the base photo last, once at least the chocolate overlays exist, or
you'll see a bare bowl with no coating options for any selection.

## Canvas — read this first

Every image in every subfolder below must be shot/exported to the **exact
same canvas**: same crop, same camera angle, same lighting direction, same
bowl position and size in frame, same background. That's what lets the app
stack a sauce photo directly on top of the base photo, and a topping photo
directly on top of that, with zero repositioning — if any asset's bowl is a
different size or the camera angle drifts even slightly, selections will
visibly "jump" when switching options.

- **Canvas size:** 1000 × 840 px (25:21 aspect ratio — 4× the app's internal
  250×210 design unit box, so the hero step stays sharp at larger display
  sizes). Export everything at this exact resolution.
- **Format:** WebP. Use opaque WebP for the base photo (no transparency
  needed — it's always the bottom layer). Use transparent WebP for every
  overlay (sauce, topping, effect) so the base and any lower overlay show
  through around the food.
- **Camera:** eye-level-to-slightly-elevated (~30–40° above the plate), bowl
  centered, consistent focal length across every shot. Shoot all variants of
  a series (all 7 chocolates, all 15 toppings) in one sitting with the camera
  locked down (tripod, no repositioning between shots) — this is the single
  most important thing for alignment.
- **Lighting:** soft, direction-consistent key light from the upper-left
  (matches how the rest of the site's product photography is lit), gentle
  fill from the right so the far side of the bowl isn't a black silhouette.
  Keep the same lighting setup across the whole series — a chocolate overlay
  photographed under different lighting than the base will look pasted-on.
- **Bowl / product position:** the bowl fills roughly the center 80% of the
  frame with even margin on all sides — leave a small safe border (~40px at
  this resolution) so nothing is cropped at the canvas edge and there's room
  for a topping piece to sit slightly outside the bowl's rim without leaving
  the frame.
- **Background:** identical, uncluttered surface/backdrop across every shot
  in every subfolder (same table, same backdrop color, same crop) — it's the
  thing most likely to give away misalignment if it isn't.

## `base/` — the plain bowl (required to enable photo mode at all)

| File | Contents |
|---|---|
| `profiterole-base.webp` | The bowl with plain choux buns only — no chocolate coating, no toppings. Opaque WebP, 1000×840, per the canvas spec above. |

## `sauces/` — one overlay per chocolate, keyed to `chocolates[].id` in `app/src/data/menu.ts`

Each is a **transparent WebP**, 1000×840, showing only the coated buns (chocolate
coating + gloss + any drizzle) against transparency, aligned so it drops directly
onto `base/profiterole-base.webp` with no offset. These must look *materially*
different from each other in **texture**, not just hue — e.g. `white` should read as
a matte/creamy coating, `dark` as a glossy near-black shell, `strawberry` as a
pink, creamy (not just pink-tinted-brown) coating, `bueno`/`gianduia` as a
hazelnut-flecked coating, distinct from plain `milk`/`classic`.

| File | Chocolate (`id`) | Name (Greek) |
|---|---|---|
| `milk-coat.webp` | `milk` | Γάλακτος |
| `classic-coat.webp` | `classic` | Κλασική |
| `white-coat.webp` | `white` | Λευκή |
| `dark-coat.webp` | `dark` | Υγείας |
| `gianduia-coat.webp` | `gianduia` | Gianduia |
| `strawberry-coat.webp` | `strawberry` | Φράουλα |
| `bueno-coat.webp` | `bueno` | Bueno |

## `effects/` — optional transient pour effect per chocolate

Each is a **transparent WebP**, 1000×840, same alignment as `sauces/`. Shown
briefly on top of the matching `sauces/*-coat.webp` right after a chocolate is
selected (e.g. a glossy "just poured" sheen or a few extra drips), then faded
out automatically, leaving the static coat overlay. **Fully optional per
chocolate** — if a chocolate has a `sauces/*-coat.webp` but no matching file
here, the app just shows the static overlay with no transient effect; nothing
breaks.

| File | Chocolate (`id`) |
|---|---|
| `milk-pour.webp` | `milk` |
| `classic-pour.webp` | `classic` |
| `white-pour.webp` | `white` |
| `dark-pour.webp` | `dark` |
| `gianduia-pour.webp` | `gianduia` |
| `strawberry-pour.webp` | `strawberry` |
| `bueno-pour.webp` | `bueno` |

## `toppings/` — one overlay per topping, keyed to `toppingGroups[].items[].id` in `app/src/data/menu.ts`

Each is a **transparent WebP**, 1000×840, showing the topping sprinkled/placed
on the coated bowl (shoot on top of any one chocolate coat — the app composites
this layer on top of whichever sauce is selected, so the topping photo itself
should contain *only* the topping pieces + shadow, not the coating). This same
file is reused by the app for the brief "falling in" entrance animation (small
circular crops of it animate in before the full overlay settles), so the
topping pieces should be reasonably legible/recognizable in a tight crop, not
just visible at full-image scale.

| File | Topping (`id`) | Group | Name (Greek) |
|---|---|---|---|
| `walnut.webp` | `walnut` | Ξηροί καρποί | Καρύδι |
| `hazelnut.webp` | `hazelnut` | Ξηροί καρποί | Φουντούκι |
| `almond.webp` | `almond` | Ξηροί καρποί | Αμύγδαλο |
| `oreo.webp` | `oreo` | Μπισκότα | Oreo |
| `digestive.webp` | `digestive` | Μπισκότα | Digestive |
| `lotusb.webp` | `lotusb` | Μπισκότα | Lotus Biscoff |
| `strawberry.webp` | `strawberry` | Φρούτα | Φράουλα |
| `banana.webp` | `banana` | Φρούτα | Μπανάνα |
| `cherry.webp` | `cherry` | Φρούτα | Κεράσι |
| `chocsyrup.webp` | `chocsyrup` | Σιρόπια | Σιρόπι σοκολάτας |
| `caramel.webp` | `caramel` | Σιρόπια | Καραμέλα |
| `strawsyrup.webp` | `strawsyrup` | Σιρόπια | Σιρόπι φράουλας |
| `mms.webp` | `mms` | Καραμέλες | M&M's |
| `marshmallow.webp` | `marshmallow` | Καραμέλες | Marshmallow |
| `gummy.webp` | `gummy` | Καραμέλες | Ζελεδάκια |

> Note: the chocolate `strawberry` (a sauce, in `sauces/`) and the fruit
> topping `strawberry` (in `toppings/`) share an id but live in separate
> folders/records — no collision, just don't cross the two up.

## Falling-piece effect (no extra assets needed)

The topping entrance animation ("piece falls in, then the full overlay
settles") is generated entirely from each topping's single `toppings/*.webp`
file — the app crops a few small circular regions out of it via CSS and
animates them in before showing the full overlay. You don't need to supply
separate "falling piece" sprites; one well-composed overlay photo per topping
covers both uses.

## Sauce transition (no liquid simulation)

Selecting a chocolate is *not* physically simulated pouring. It's: the static
`sauces/*-coat.webp` overlay fades/reveals in, and — only if
`effects/*-pour.webp` exists for that chocolate — a brief extra sheen/drip
layer flashes on top of it and fades out, leaving the static overlay. If no
pour-effect asset exists for a chocolate, the reveal still happens with just
the static overlay (this is the current state for every chocolate, since
`effects/` is empty).

## Performance notes for when these are filled in

- Prefer WebP throughout (transparency only on overlay layers, per above).
- The base photo and the currently-selected chocolate's overlay are the only
  images needed to render the current preview state — don't eagerly load
  every topping/chocolate photo on first paint. If adding preloading, prefer
  warming the *next likely* selection (e.g. `<link rel="preload">` for the
  first chocolate once the configurator opens) over the full set.
- Keep each file reasonably compressed (target well under 200KB per overlay)
  — these load into a small on-screen box (250×210 CSS px, scaled up for the
  hero step), so the 1000×840 canvas gives headroom for sharpness on
  high-DPI screens without needing print-resolution file sizes.
- Reserve layout space via the fixed-size wrapper already in
  `ProfiterolePreview.tsx` (unchanged by asset loading) so images popping in
  never cause layout shift.
