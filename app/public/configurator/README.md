# Profiterole configurator — photo asset spec

This folder is where real product photography for "Φτιάξε το δικό σου
προφιτερόλ" (`app/src/screens/Configurator.tsx`) goes once it's shot/generated.
**Nothing in here is a placeholder photo** — `states/` and `toppings/` are
intentionally near-empty (only `.gitkeep`, plus whatever real assets have
landed so far) until real Evangelou photography/renders exist. With an
asset missing, the app renders the existing procedural CSS/SVG illustration
for that specific selection and looks/behaves exactly as it always has.

Do not add stock photos, AI mockups, or anything not an actual Evangelou
product render "to fill folders in." A missing file here means the pipeline
is ready and waiting for that one asset, not that something's broken.

## Strategy: full-frame states, not stacked sauce layers

Chocolate selection is **not** a transparent sauce layer stacked on a base
photo. Each chocolate that has real photography gets one **complete,
opaque, full-frame photo of the finished dessert** — bowl, buns, sauce,
lighting, everything baked into a single image. Toppings are the one thing
that *is* layered: a transparent sprite sheet of loose pieces, animated on
top of whichever state photo (or procedural illustration) is currently
showing.

## How the fallback works

`app/src/configurator/ProfiterolePreview.tsx` looks up a state image for the
current chocolate selection (`states/{chocId}.webp`, or `states/base.webp`
when nothing is selected yet) and probes whether it loads. If it does, that
photo renders full-frame. If it doesn't (file missing, or that chocolate
simply has no photo yet), **only that selection** falls back to the
procedural illustration — picking an unphotographed chocolate never breaks
photo mode for the ones that do have real photography, and switching back
to a photographed chocolate immediately returns to photo mode.

Toppings follow the same per-asset rule, independently of the sauce/base
layer: a topping with a loadable sprite sheet renders as real sprite pieces;
any other topping renders as the original colored dot/crumb/drizzle-squiggle
— on top of a photo state or the procedural illustration alike.

## Canvas — read this first

Every state photo must be shot/exported to the **exact same canvas**: same
crop, same camera angle, same lighting direction, same bowl position and
size in frame, same background. That's what makes switching between two
state photos read as "the same bowl, different sauce" rather than a jump
cut — the app crossfades between them (see below), and a crossfade between
two differently-framed photos looks like a mistake, not a transition.

- **Canvas size:** 1000 × 840 px (25:21 aspect ratio — 4× the app's internal
  250×210 design unit box, so the hero step stays sharp at larger display
  sizes). Export every state photo at this exact resolution.
- **Format:** WebP, opaque (no transparency needed or used — each state
  photo is a complete, self-contained image, always the only/bottom layer
  for that selection).
- **Camera:** eye-level-to-slightly-elevated (~30–40° above the plate), bowl
  centered, consistent focal length across every shot. Shoot every chocolate
  variant in one sitting with the camera locked down (tripod, no
  repositioning) — this is the single most important thing for a clean
  crossfade.
- **Lighting:** soft, direction-consistent key light from the upper-left,
  gentle fill from the right so the far side of the bowl isn't a black
  silhouette. Identical setup across the whole series.
- **Bowl / product position:** the bowl fills roughly the center 80% of the
  frame with even margin on all sides — leave a small safe border (~40px at
  this resolution) so nothing crops at the canvas edge and there's room for
  a topping sprite piece to sit slightly outside the bowl's rim.
- **Background:** identical, uncluttered surface/backdrop across every
  state photo — same table, same backdrop color, same crop.

## `states/` — one full-frame photo per dessert state

| File | Meaning |
|---|---|
| `base.webp` | Plain choux buns in the bowl, no chocolate, no toppings — shown whenever no chocolate is selected yet. |
| `milk.webp` | The complete dessert with milk chocolate (`chocolates[].id === "milk"`). |
| `strawberry.webp` | The complete dessert with strawberry glaze (`chocolates[].id === "strawberry"`). |

Any other chocolate id (`classic`, `white`, `dark`, `gianduia`, `bueno`) has
no entry yet — those selections render the procedural illustration until a
matching `states/{id}.webp` is added and wired into `STATE_IMAGES` in
`app/src/configurator/previewAssets.ts`.

`base.webp`/`milk.webp`/`strawberry.webp` are real photography, confirmed
1000×840px RGB (opaque) each, with matching bowl position/crop/lighting
across all three — they align correctly when crossfaded. All three currently
have a thin (~1–1.5% of width) encoding-seam artifact along the right edge;
`ProfiterolePreview.tsx` applies a small uniform overscale to every photo
layer (`PHOTO_EDGE_TRIM_SCALE`) so the parent's clipped box crops it off
without touching these files. If a future regeneration removes that seam,
that scale can be dropped back to 1 — it's not load-bearing for alignment.

## `toppings/` — transparent sprite sheets for animated toppings

A topping sprite is a **transparent WebP sprite sheet**: several loose,
individually-recognizable piece variants arranged in one horizontal strip
of equal-width frames, sampled via CSS `background-position` steps (no
per-frame JSON/coordinates needed — just equal-width tiles).

| File | Topping (`id`) | Frames |
|---|---|---|
| `hazelnut-sprites.webp` | `hazelnut` | 4 (confirmed: real asset is 1024×256px, exactly 4×256px tiles) |

The frame count is set in `app/src/configurator/previewAssets.ts`, the
`sprite.frames` value on the `hazelnut` entry in `TOPPING_VISUALS` — it
must always match the real file's actual grid or the crops will sample the
wrong regions of the sheet. Each tile shows one clearly separated
cluster/piece of hazelnut (shadow included) against full transparency
around it — not a full bowl shot, confirmed against the real asset.

Any topping without a `sprite` entry keeps rendering as the original
procedural dot/crumb/drizzle-squiggle. Extending this to more toppings later
is the same pattern: add a sprite sheet under `toppings/`, add a `sprite: {
image, frames }` entry to that topping in `TOPPING_VISUALS`.

## Sauce transitions: crossfade, not simulation

Switching chocolates is not a physically simulated pour. Selecting a new
chocolate crossfades the previous full-frame state photo into the new one
(the outgoing photo stays static underneath while the incoming one fades in
on top, then the outgoing one is dropped) — see `useStateImageTransition` in
`app/src/configurator/previewHelpers.ts`. This works identically for
base → milk, base → strawberry, and milk ↔ strawberry, and needs no extra
per-transition asset.

## Topping animation

A topping with a sprite falls in as 1-3 small cropped sprite pieces (reusing
the existing topping enter/exit timing — `useToppingTransitions` — and the
same fall/exit CSS classes as the procedural dots) and then **stays in
place** once landed; there's no separate "settle" swap to a different image.
Removing the topping plays the existing exit animation on those same
pieces.

## Performance notes

- Only the base state and whichever chocolate is currently selected need to
  be loaded at once — don't eagerly fetch every `states/*.webp` on first
  paint. If adding preloading later, warm the *next likely* selection
  rather than the full set.
- Keep each state photo reasonably compressed (well under 300KB) — the
  1000×840 canvas gives headroom for sharpness on high-DPI screens without
  needing print-resolution file sizes.
- The fixed-size wrapper in `ProfiterolePreview.tsx` reserves layout space
  regardless of which image is loaded, so nothing causes layout shift.
