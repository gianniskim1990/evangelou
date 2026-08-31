// DEMO DATA — τιμές και προϊόντα ενδεικτικά, προς αντικατάσταση από τον πελάτη.
// Δομή σχεδιασμένη ώστε να αντικατασταθεί εύκολα από API αργότερα.

export const chocolates = [
  { id: "milk", name: "Γάλακτος", desc: "η κλασική επιλογή, γλυκιά και απαλή" },
  { id: "classic", name: "Κλασική", desc: "η αυθεντική γεύση του μαγαζιού" },
  { id: "white", name: "Λευκή", desc: "πιο γλυκιά, με άρωμα βανίλιας" },
  { id: "dark", name: "Πικρή", desc: "για όσους θέλουν λιγότερη ζάχαρη" },
  { id: "gianduia", name: "Τζιαντούγια", desc: "σοκολάτα με φουντούκι, για όποιον θέλει κάτι πιο βαρύ" },
  { id: "strawberry", name: "Φράουλα", desc: "φρουτώδης, ανάλαφρη επιλογή" },
  { id: "bueno", name: "Bueno", desc: "σοκολάτα γάλακτος με φουντούκι, πιο πλούσια υφή" },
];

export const sizes = [
  { id: "solo", name: "Ατομικό", price: 4.5 },
  { id: "duo", name: "Μερίδα για δύο", price: 8.5 },
  { id: "family", name: "Οικογενειακό (1 κιλό)", price: 16.0 },
];

export const bases = [
  { id: "classic", name: "Προφιτερόλ σου", extra: 0 },
  { id: "icecream", name: "Με παγωτό", extra: 1.5 },
  { id: "chilly", name: "Chilly", extra: 2.0 },
  { id: "dubai", name: "Dubai", extra: 2.5 },
  { id: "lotus", name: "Lotus", extra: 2.0 },
];

// Ενδεικτικές ομάδες — η τελική λίστα των 30+ υλικών θα δοθεί από τον πελάτη.
export const toppingGroups = [
  {
    id: "nuts", name: "Ξηροί καρποί",
    items: [{ id: "walnut", name: "Καρύδι" }, { id: "hazelnut", name: "Φουντούκι" }, { id: "almond", name: "Αμύγδαλο" }],
  },
  {
    id: "biscuits", name: "Μπισκότα",
    items: [{ id: "oreo", name: "Θρύψαλα Oreo" }, { id: "digestive", name: "Digestive" }, { id: "lotusb", name: "Μπισκότο Lotus" }],
  },
  {
    id: "fruit", name: "Φρούτα",
    items: [{ id: "strawberry", name: "Φράουλα" }, { id: "banana", name: "Μπανάνα" }, { id: "cherry", name: "Κεράσι" }],
  },
  {
    id: "syrup", name: "Σιρόπια",
    items: [{ id: "chocsyrup", name: "Σοκολάτα" }, { id: "caramel", name: "Καραμέλα" }, { id: "strawsyrup", name: "Φράουλα" }],
  },
  {
    id: "candy", name: "Καραμέλες",
    items: [{ id: "mms", name: "M&M's" }, { id: "marshmallow", name: "Marshmallow" }, { id: "gummy", name: "Ζελεδάκια" }],
  },
];
export const TOPPING_EXTRA_PRICE = 0.5;
export const FREE_TOPPINGS = 3;

export const groups = [
  { id: "profiterole", name: "Προφιτερόλ", categories: ["profiterole", "profiterole_icecream", "profiterole_chilly", "profiterole_dubai", "profiterole_lotus"] },
  { id: "icecream", name: "Παγωτά", categories: ["icecream", "icecream_ball", "icecream_mini", "cones"] },
  { id: "cakes", name: "Τούρτες", categories: ["cakes", "icecream_cakes", "cupcakes"] },
  { id: "individual", name: "Ατομικά γλυκά", categories: ["sweets_individual", "tarts", "cheesecake", "millefeuille", "creams"] },
  { id: "tray", name: "Ταψιού & σιροπιαστά", categories: ["tray_sweets", "syrup_sweets", "touloumpakia"] },
  { id: "chocolate", name: "Σοκολάτα", categories: ["chocolates_cat", "pralines", "choc_syringes", "marshmallows"] },
  { id: "treats", name: "Κεράσματα & βουτήματα", categories: ["cookies", "treats", "diabetic"] },
  { id: "drinks", name: "Ροφήματα", categories: ["coffees", "milkshakes", "hot_drinks", "juices", "sodas"] },
  { id: "seasonal", name: "Εποχιακά", categories: ["easter", "halva"] },
];

export const categoryNames = {
  profiterole: "Προφιτερόλ", profiterole_icecream: "Προφιτερόλ με παγωτό", profiterole_chilly: "Προφιτερόλ Chilly",
  profiterole_dubai: "Προφιτερόλ Dubai", profiterole_lotus: "Προφιτερόλ Lotus",
  icecream: "Παγωτό", icecream_ball: "Παγωτά μπάλα", icecream_mini: "Παγωτίνια", cones: "Χωνάκια",
  cakes: "Τούρτες", icecream_cakes: "Τούρτες παγωτό", cupcakes: "Κεράκια",
  sweets_individual: "Γλυκά ατομικά", tarts: "Τάρτες", cheesecake: "Cheesecake", millefeuille: "Millefeuille", creams: "Κρέμες",
  tray_sweets: "Γλυκά ταψιού", syrup_sweets: "Σιροπιαστά", touloumpakia: "Τουλουμπάκια",
  chocolates_cat: "Σοκολάτες", pralines: "Πραλίνες", choc_syringes: "Σύριγγες σοκολάτας", marshmallows: "Marshmallows",
  cookies: "Βουτήματα | Κουλουράκια", treats: "Γλυκά κεράσματα", diabetic: "Κεράσματα για διαβητικούς",
  coffees: "Καφέδες", milkshakes: "Milkshakes", hot_drinks: "Ροφήματα", juices: "Χυμοί", sodas: "Αναψυκτικά",
  easter: "Πασχαλινά", halva: "Χαλβάδες νηστίσιμοι",
};

// price: αριθμός σε € · perKilo: true όταν η τιμή είναι /κιλό · diabetic: σήμα χωρίς ζάχαρη
export const products = {
  profiterole: [
    { name: "Προφιτερόλ κλασικό", price: 4.8 }, { name: "Προφιτερόλ διπλή σοκολάτα", price: 5.2 }, { name: "Προφιτερόλ φουντούκι", price: 5.4 },
  ],
  profiterole_icecream: [
    { name: "Προφιτερόλ με παγωτό βανίλια", price: 5.8 }, { name: "Προφιτερόλ με παγωτό σοκολάτα", price: 5.8 }, { name: "Προφιτερόλ με παγωτό φράουλα", price: 5.8 },
  ],
  profiterole_chilly: [
    { name: "Προφιτερόλ Chilly βανίλια", price: 6.2 }, { name: "Προφιτερόλ Chilly σοκολάτα", price: 6.2 }, { name: "Προφιτερόλ Chilly φράουλα", price: 6.2 },
  ],
  profiterole_dubai: [
    { name: "Προφιτερόλ Dubai πιστάτσιο", price: 6.8 }, { name: "Προφιτερόλ Dubai σοκολάτα γάλακτος", price: 6.8 },
  ],
  profiterole_lotus: [
    { name: "Προφιτερόλ Lotus", price: 6.5 }, { name: "Προφιτερόλ Lotus με λευκή σοκολάτα", price: 6.8 },
  ],
  icecream: [
    { name: "Παγωτό βανίλια 500γρ", price: 5.5 }, { name: "Παγωτό σοκολάτα 500γρ", price: 5.5 }, { name: "Παγωτό φράουλα 500γρ", price: 5.5 },
  ],
  icecream_ball: [
    { name: "Μπάλα βανίλια", price: 1.8 }, { name: "Μπάλα σοκολάτα", price: 1.8 }, { name: "Μπάλα φράουλα", price: 1.8 }, { name: "Μπάλα πιστάτσιο", price: 2.0 },
  ],
  icecream_mini: [
    { name: "Παγωτίνι βανίλια-σοκολάτα", price: 2.2 }, { name: "Παγωτίνι φράουλα", price: 2.2 },
  ],
  cones: [
    { name: "Χωνάκι απλό", price: 2.5 }, { name: "Χωνάκι με επικάλυψη σοκολάτας", price: 3.0 },
  ],
  cakes: [
    { name: "Τούρτα σοκολάτα", price: 26.0, perKilo: true }, { name: "Τούρτα φρούτων", price: 24.0, perKilo: true }, { name: "Τούρτα προφιτερόλ", price: 30.0, perKilo: true },
  ],
  icecream_cakes: [
    { name: "Τούρτα παγωτό βανίλια-σοκολάτα", price: 28.0, perKilo: true }, { name: "Τούρτα παγωτό φράουλα", price: 28.0, perKilo: true },
  ],
  cupcakes: [
    { name: "Κεράκι βανίλια", price: 2.8 }, { name: "Κεράκι σοκολάτα", price: 2.8 }, { name: "Κεράκι red velvet", price: 3.2 },
  ],
  sweets_individual: [
    { name: "Πάστα αμυγδάλου", price: 4.0 }, { name: "Εκλέρ σοκολάτα", price: 3.8 }, { name: "Ρυζόγαλο φούρνου", price: 3.5 },
  ],
  tarts: [
    { name: "Τάρτα φράουλα", price: 4.5 }, { name: "Τάρτα λεμόνι", price: 4.5 }, { name: "Τάρτα σοκολάτα", price: 4.8 },
  ],
  cheesecake: [
    { name: "Cheesecake φράουλα", price: 4.8 }, { name: "Cheesecake Oreo", price: 5.0 },
  ],
  millefeuille: [
    { name: "Millefeuille κρέμα", price: 4.2 }, { name: "Millefeuille σοκολάτα", price: 4.5 },
  ],
  creams: [
    { name: "Κρέμα καραμελέ", price: 3.5 }, { name: "Κρέμα σοκολάτας", price: 3.5 },
  ],
  tray_sweets: [
    { name: "Μπακλαβάς", price: 4.0 }, { name: "Κανταΐφι", price: 3.8 },
  ],
  syrup_sweets: [
    { name: "Ρεβανί", price: 3.5 }, { name: "Σαμαλί", price: 3.5 },
  ],
  touloumpakia: [
    { name: "Τουλουμπάκια (μερίδα)", price: 4.5 },
  ],
  chocolates_cat: [
    { name: "Σοκολάτα γάλακτος με φουντούκι", price: 5.5 }, { name: "Σοκολάτα υγείας 70%", price: 5.5 },
  ],
  pralines: [
    { name: "Πραλίνες, κουτί 250γρ", price: 9.0 }, { name: "Πραλίνες με λικέρ", price: 10.0 },
  ],
  choc_syringes: [
    { name: "Σύριγγα σοκολάτας γάλακτος", price: 3.0 }, { name: "Σύριγγα σοκολάτας λευκής", price: 3.0 },
  ],
  marshmallows: [
    { name: "Marshmallow με σοκολάτα", price: 2.5 },
  ],
  cookies: [
    { name: "Κουλουράκια βουτύρου, μερίδα", price: 4.0 }, { name: "Παξιμαδάκια αμυγδάλου", price: 4.5 },
  ],
  treats: [
    { name: "Μελομακάρονο", price: 1.0 }, { name: "Κουραμπιές", price: 1.0 },
  ],
  diabetic: [
    { name: "Τούρτα χωρίς ζάχαρη, κομμάτι", price: 4.5, diabetic: true }, { name: "Κέικ χωρίς ζάχαρη", price: 4.0, diabetic: true },
  ],
  coffees: [
    { name: "Espresso", price: 2.0 }, { name: "Καπουτσίνο", price: 2.8 }, { name: "Freddo espresso", price: 3.0 },
  ],
  milkshakes: [
    { name: "Milkshake βανίλια", price: 4.0 }, { name: "Milkshake σοκολάτα", price: 4.0 },
  ],
  hot_drinks: [
    { name: "Ζεστή σοκολάτα", price: 3.5 }, { name: "Τσάι", price: 2.5 },
  ],
  juices: [
    { name: "Χυμός πορτοκάλι φρέσκος", price: 3.0 }, { name: "Χυμός φράουλα", price: 3.0 },
  ],
  sodas: [
    { name: "Κόκα κόλα", price: 2.0 }, { name: "Σόδα", price: 2.0 },
  ],
  easter: [
    { name: "Τσουρέκι Πάσχα", price: 6.5 }, { name: "Κουλουράκια Πάσχα", price: 4.0 },
  ],
  halva: [
    { name: "Χαλβάς σιμιγδαλένιος", price: 4.0 }, { name: "Χαλβάς με ταχίνι", price: 4.5 },
  ],
};

// Rails στην αρχική — δεν είναι κατηγορίες
export const offers = [
  { cat: "profiterole_dubai", name: "Προφιτερόλ Dubai πιστάτσιο", price: 6.8, was: 7.8 },
  { cat: "cakes", name: "Τούρτα προφιτερόλ", price: 30.0, was: 34.0, perKilo: true },
  { cat: "coffees", name: "Freddo espresso", price: 3.0, was: 3.5 },
];
export const popular = [
  { cat: "profiterole", name: "Προφιτερόλ κλασικό", price: 4.8 },
  { cat: "profiterole_dubai", name: "Προφιτερόλ Dubai πιστάτσιο", price: 6.8 },
  { cat: "cheesecake", name: "Cheesecake Oreo", price: 5.0 },
  { cat: "coffees", name: "Freddo espresso", price: 3.0 },
];

export const store = {
  name: "Ζαχαροπλαστική Ευαγγέλου",
  address: "Παύλου Μελά 6, 653 02, Καβάλα",
  phone: "2510 226999",
  phoneHref: "2510226999",
  site: "evangelouprofiterole.gr",
  instagram: "@evangelou_zaharoplastiki",
  hours: "09:00–23:00", // placeholder ωράριο — προς επιβεβαίωση από τον πελάτη
  deliveryMinOrder: 12.0,
  deliveryFee: 2.5,
};
