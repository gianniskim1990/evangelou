# Ζαχαροπλαστική Ευαγγέλου — web app παραγγελιών

Demo παρουσίασης για το ζαχαροπλαστείο Ευαγγέλου στην Καβάλα. React + TypeScript + Tailwind CSS, mobile-first, με ένα μικρό Vercel Functions backend αποκλειστικά για το admin panel (βλ. παρακάτω) — η ροή παραγγελίας παραμένει front-end only όπως στο αρχικό brief.

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
- `src/admin/` — `/admin` panel (login + κατάλογος/τιμές editor). `api/` — τα δύο Vercel Functions που το υποστηρίζουν.
- `src/MenuContext.tsx` — φορτώνει τα admin-edited overrides από `/api/overrides` και τα συγχωνεύει πάνω στο seed data του `data/menu.ts`· το καταναλώνουν τόσο ο Κατάλογος/DesktopHome όσο και το admin panel.

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

## Admin panel (`/admin`)

Διαχείριση κατηγοριών/προϊόντων/τιμών, live στο πραγματικό site — για να το βλέπουν όλοι οι επισκέπτες, όχι μόνο ο browser που έκανε την αλλαγή, οι αλλαγές αποθηκεύονται σε πραγματική βάση (Redis μέσω Upstash, ό,τι το Vercel Storage tab αποκαλεί σήμερα "KV"), όχι localStorage.

**Ρύθμιση στο Vercel (μία φορά):**

1. Storage tab του project → **Create Database** → **Upstash** (ή ό,τι εμφανίζεται ως "KV"/Redis) → Connect. Αυτό βάζει αυτόματα τα env vars (`KV_REST_API_URL`/`KV_REST_API_TOKEN` ή `UPSTASH_REDIS_REST_URL`/`UPSTASH_REDIS_REST_TOKEN` — το API δέχεται και τα δύο ονόματα).
2. Settings → Environment Variables → πρόσθεσε `ADMIN_PASSWORD` με τον κωδικό που θες για το `/admin` (δεν μπαίνει ποτέ στον κώδικα/git).
3. Redeploy.

**Πώς δουλεύει:**

- `api/overrides.ts` — `GET` (δημόσιο, το διαβάζει ο Κατάλογος/DesktopHome) και `POST` (προστατευμένο με `ADMIN_PASSWORD` μέσω header, γράφει στο Redis). Κάθε save στέλνει μόνο την κατηγορία που άλλαξε· ο server τη συγχωνεύει με τα ήδη αποθηκευμένα overrides, δεν τα σβήνει.
- `api/admin-login.ts` — επαληθεύει τον κωδικό server-side.
- Το login είναι ένα ελαφρύ shared-password gate (sessionStorage, χωρίς λογαριασμούς/hashing/rate-limit) — αρκετό να κρατήσει έξω περαστικούς επισκέπτες, όχι πραγματικό auth σύστημα. Το `/admin` δεν εμφανίζεται πουθενά ως link στο customer-facing site.
- Scope: επεξεργασία ονόματος/τιμής/`perKilo`/`diabetic` σε υπάρχοντα προϊόντα, προσθήκη/διαγραφή προϊόντων, μετονομασία κατηγορίας, μέσα στις ήδη υπάρχουσες 34 κατηγορίες. Δεν υποστηρίζει προσθήκη νέων κατηγοριών/ενοτήτων, ούτε επεξεργασία configurator (σοκολάτες/βάσεις/toppings), Προσφορών/Δημοφιλέστερων, ή στοιχείων καταστήματος — μπορεί να επεκταθεί αργότερα αν χρειαστεί.
- "Επαναφορά όλων" στο admin σβήνει όλα τα overrides και επιστρέφει στα seed δεδομένα του `data/menu.ts`.

**Τοπικό dev:** το `npm run dev` (καθαρό Vite) δεν εκτελεί τα `api/*.ts` — το site λειτουργεί κανονικά με τα seed δεδομένα (η μπάρα "δεν βρέθηκε σύνδεση με το menu storage" εμφανίζεται στο `/admin`), αλλά login/save δεν θα δουλέψουν μέχρι να δοκιμαστούν στο πραγματικό deployment (ή με `vercel dev` αν έχεις τον Vercel CLI και τα env vars locally).

## Σχεδιαστικές αποφάσεις

- **Καμία υπάρχουσα σκαλωσιά στο repo** — φτιάχτηκε από την αρχή Vite + React + TypeScript + Tailwind v4 στο `app/`, όπως ζητήθηκε στο brief ("React με Tailwind, mobile-first").
- **Cart snapshot στο submit**: το πρωτότυπο (`.dc.html`) δεν άδειαζε το καλάθι μετά την υποβολή παραγγελίας — η οθόνη επιβεβαίωσης/κατάστασης διάβαζε ζωντανά το `cart` state, το οποίο έμενε ως είχε. Εδώ, στο `submitOrder` παίρνουμε ένα snapshot της παραγγελίας (`OrderSnapshot`) και αδειάζουμε το καλάθι, ώστε μετά την ολοκλήρωση να μη μένει το παλιό καλάθι ενεργό στην αρχική/κατάλογο. Οπτικά και λειτουργικά οι οθόνες επιβεβαίωσης/κατάστασης δείχνουν ακριβώς τα ίδια στοιχεία με πριν.
- **Ώρες καταστήματος**: τα slots παραλαβής και το "κλειστό τώρα" υπολογίζονται από το πραγματικό `store.hours` ("09:00–23:00") αντί για hardcoded 9/23 όπως στο πρωτότυπο — ίδιο αποτέλεσμα με τα σημερινά demo δεδομένα, αλλά σωστό αν αλλάξει το ωράριο.
- Όλα τα υπόλοιπα (παλέτα 6 χρωμάτων, τυπογραφία Literata/Commissioner, configurator 5 βημάτων με ζωντανή οπτικοποίηση, sticky ομάδες καταλόγου με scrollspy, pickup/delivery/προπαραγγελία τούρτας, mock πληρωμή με κάρτα, cart drawer πάντα προσβάσιμο) ακολουθούν πιστά το εγκεκριμένο design.
- **Stock εικόνες χωρίς migration σε βάση**: τα image paths υπολογίζονται deterministic-a από `slugify(όνομα)`/`categoryId` (`src/lib/images.ts`), όχι από αποθηκευμένο `imageUrl` πεδίο — οπότε το admin panel δεν χρειάζεται να διαχειρίζεται εικόνες καθόλου· απλά πρόσθεσε το σωστά ονομασμένο `.webp` και εμφανίζεται αυτόματα σε προϊόν/κατηγορία.
- **Admin panel με πραγματικό backend, όχι localStorage**: το αρχικό brief απέκλειε ρητά admin panel/backend για το phase 1 demo· το ζητήσαμε ρητά ως phase 2. Επιλέχθηκε πραγματική βάση (Redis/Upstash μέσω Vercel Functions) αντί για localStorage ακριβώς επειδή το ζητούμενο ήταν οι αλλαγές τιμών/καταλόγου να φαίνονται σε όλους τους επισκέπτες του live site, όχι μόνο στον browser του διαχειριστή.
