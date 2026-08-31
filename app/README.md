# Ζαχαροπλαστική Ευαγγέλου — web app παραγγελιών

Demo παρουσίασης, front-end only (χωρίς backend), για το ζαχαροπλαστείο Ευαγγέλου στην Καβάλα. React + TypeScript + Tailwind CSS, mobile-first.

Χτίστηκε πάνω στο design που παραδόθηκε από το Claude Design (βλ. `../project/Evaggelou App.dc.html`, `../chats/chat1.md`).

## Εκκίνηση

```bash
npm install
npm run dev         # dev server
npm run build       # type-check + production build
npm run typecheck   # type-check only
npm run lint        # oxlint
npm run images:stock  # one-time stock photo importer (see below)
```

## Δομή

- `src/data/menu.ts` — όλα τα δεδομένα (κατηγορίες, προϊόντα, σοκολάτες, βάσεις, toppings, στοιχεία καταστήματος). Οι τιμές και η λίστα toppings είναι ενδεικτικές (demo), προς αντικατάσταση από τον πελάτη· δομημένο ώστε να αντικατασταθεί εύκολα από API αργότερα.
- `src/AppContext.tsx` — όλη η κατάσταση της εφαρμογής (καλάθι, configurator, checkout, παραγγελία) μέσω React Context, χωρίς localStorage.
- `src/screens/` — οι 6 οθόνες: Αρχική, Κατάλογος, Configurator, Checkout, Επιβεβαίωση, Κατάσταση, συν `DesktopHome.tsx`: ξεχωριστό sidebar+grid layout (κατηγορίες, search, Delivery/Παραλαβή, κάρτες με φωτογραφία) που εμφανίζεται **μόνο στην Αρχική σε πλάτος ≥1024px** (Tailwind `lg:`), εμπνευσμένο από αντίστοιχο desktop layout άλλου project (SouvLéri). Το mobile (και όλες οι άλλες οθόνες, σε όποιο πλάτος) μένουν ως έχουν — δεν πειράχτηκαν.
- `src/components/` — header (top/back), bottom nav, cart bar, cart drawer, `StockImage` (lazy stock photo with fallback).
- `src/lib/slug.ts`, `src/lib/images.ts` — Greek→Latin slugs and the `/images/products/{slug}.webp` / `/images/categories/{slug}.webp` path convention, shared between the app and the image importer.
- `scripts/fetch-stock-images.mjs` — one-time (re-runnable) Pexels stock photo importer.

## Stock εικόνες προϊόντων (Pexels)

`npm run images:stock` κατεβάζει μία landscape εικόνα ανά κατηγορία (37→34 πραγματικές κατηγορίες) και μία square εικόνα ανά προϊόν από το Pexels API, τις μετατρέπει σε WebP με το `sharp`, και τις αποθηκεύει τοπικά:

- `public/images/categories/{category-id}.webp` (800×450, ποιότητα 68)
- `public/images/products/{slug-του-ονόματος}.webp` (480×480, ποιότητα 72)
- `public/stock-image-credits.json` — Pexels photo id, φωτογράφος και source URL για κάθε εικόνα

Το site **δεν καλεί ποτέ το Pexels API στο runtime** — το `src/lib/images.ts` απλώς υπολογίζει το τοπικό path από το όνομα (μέσω `slugify`). Αν λείπει ένα αρχείο εικόνας, το `StockImage` δείχνει ένα tinted placeholder αντί για broken-image icon.

**API key**: το script διαβάζει το `PEXELS_API_KEY` αποκλειστικά από local environment variable ή από `app/.env.local` (git-ignored, ποτέ `VITE_`-prefixed, ποτέ σε frontend κώδικα ή commit):

```bash
PEXELS_API_KEY=your_key npm run images:stock
npm run images:stock -- --force   # ξαναφέρνει τα πάντα
```

Ήδη υπάρχοντα αρχεία παραλείπονται εκτός αν δοθεί `--force`. Τα search queries χτίζονται από ελληνικά→αγγλικά mappings μέσα στο ίδιο το script (π.χ. "Τζιαντούγια" → "hazelnut"), βασισμένα στην κατηγορία του κάθε προϊόντος· αν δεν βρεθεί αποτέλεσμα για ένα προϊόν, γίνεται fallback στην ήδη κατεβασμένη εικόνα της κατηγορίας του.

> **Σημείωση:** αυτό το sandbox δεν έχει δικτυακή πρόσβαση στο `api.pexels.com` (οργανωτική πολιτική egress), οπότε ο importer δεν έχει τρέξει ακόμα εδώ — τρέξε `npm run images:stock` σε περιβάλλον με πρόσβαση στο internet πριν το commit, όπως απαιτεί το requirement #10.

## Σχεδιαστικές αποφάσεις

- **Καμία υπάρχουσα σκαλωσιά στο repo** — φτιάχτηκε από την αρχή Vite + React + TypeScript + Tailwind v4 στο `app/`, όπως ζητήθηκε στο brief ("React με Tailwind, mobile-first").
- **Cart snapshot στο submit**: το πρωτότυπο (`.dc.html`) δεν άδειαζε το καλάθι μετά την υποβολή παραγγελίας — η οθόνη επιβεβαίωσης/κατάστασης διάβαζε ζωντανά το `cart` state, το οποίο έμενε ως είχε. Εδώ, στο `submitOrder` παίρνουμε ένα snapshot της παραγγελίας (`OrderSnapshot`) και αδειάζουμε το καλάθι, ώστε μετά την ολοκλήρωση να μη μένει το παλιό καλάθι ενεργό στην αρχική/κατάλογο. Οπτικά και λειτουργικά οι οθόνες επιβεβαίωσης/κατάστασης δείχνουν ακριβώς τα ίδια στοιχεία με πριν.
- **Ώρες καταστήματος**: τα slots παραλαβής και το "κλειστό τώρα" υπολογίζονται από το πραγματικό `store.hours` ("09:00–23:00") αντί για hardcoded 9/23 όπως στο πρωτότυπο — ίδιο αποτέλεσμα με τα σημερινά demo δεδομένα, αλλά σωστό αν αλλάξει το ωράριο.
- Όλα τα υπόλοιπα (παλέτα 6 χρωμάτων, τυπογραφία Literata/Commissioner, configurator 5 βημάτων με ζωντανή οπτικοποίηση, sticky ομάδες καταλόγου με scrollspy, pickup/delivery/προπαραγγελία τούρτας, mock πληρωμή με κάρτα, cart drawer πάντα προσβάσιμο) ακολουθούν πιστά το εγκεκριμένο design.
- **Stock εικόνες χωρίς localStorage/admin**: το αρχικό brief αυτού του project αποκλείει ρητά localStorage και admin panel (React state only, "Εκτός σκοπού: ... admin panel"). Άρα το image importer δεν κάνει "migration σε localStorage database" όπως στο SouvLéri — αντ' αυτού τα image paths υπολογίζονται deterministic-a από `slugify(όνομα)`/`categoryId`, οπότε δεν χρειάζεται καν να αποθηκευτεί `imageUrl` πεδίο στο data file· απλά προσθέτεις το αρχείο `.webp` με το σωστό όνομα και εμφανίζεται.
