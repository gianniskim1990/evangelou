/**
 * UI-04O LOCAL-ONLY: verbatim copy of the shared ILLUSTRATIVE preparation
 * catalogue fixture (wordpress/evangelou-club/tests/fixtures/preparation/
 * catalogue-illustrative.json, status "illustrative", NOT an approved menu),
 * so the fake server can validate orders with the real UI-03C contract code
 * without reaching outside the Vite project. A test pins it to the fixture.
 */
export const PREVIEW_CATALOGUE_DOCUMENT: unknown = {
  "schema": "evc-prep-catalogue-v1",
  "catalogue_version": 1,
  "status": "illustrative",
  "pricing_policy": "undecided",
  "free_text_note": false,
  "quick_notes": [
    {
      "id": "takeaway",
      "label": "σε ποτήρι για έξω",
      "applies_to": "any"
    },
    {
      "id": "very_hot",
      "label": "πολύ ζεστός",
      "applies_to": "hot"
    },
    {
      "id": "lukewarm",
      "label": "χλιαρός",
      "applies_to": "hot"
    },
    {
      "id": "ice_side",
      "label": "πάγος στο πλάι",
      "applies_to": "cold"
    },
    {
      "id": "no_straw",
      "label": "χωρίς καλαμάκι",
      "applies_to": "cold"
    },
    {
      "id": "cinnamon",
      "label": "κανέλα στον αφρό",
      "applies_to": "foam"
    },
    {
      "id": "cocoa",
      "label": "κακάο στον αφρό",
      "applies_to": "foam"
    }
  ],
  "exclusive_quick_notes": [
    [
      "very_hot",
      "lukewarm"
    ]
  ],
  "max_quick_notes": 4,
  "coffees": [
    {
      "code": "espresso",
      "label": "Espresso",
      "temperature": "hot",
      "groups": [
        {
          "id": "shots",
          "label": "Δόση",
          "required": true,
          "options": [
            {
              "id": "single",
              "label": "Μονός"
            },
            {
              "id": "double",
              "label": "Διπλός"
            }
          ]
        },
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        },
        {
          "id": "caffeine",
          "label": "Καφεΐνη",
          "required": false,
          "options": [
            {
              "id": "decaf",
              "label": "Χωρίς καφεΐνη"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "very_hot",
        "lukewarm"
      ]
    },
    {
      "code": "freddo_espresso",
      "label": "Freddo Espresso",
      "temperature": "cold",
      "groups": [
        {
          "id": "shots",
          "label": "Δόση",
          "required": true,
          "options": [
            {
              "id": "single",
              "label": "Μονός"
            },
            {
              "id": "double",
              "label": "Διπλός"
            }
          ]
        },
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        },
        {
          "id": "milk",
          "label": "Γάλα",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Χωρίς γάλα"
            },
            {
              "id": "regular",
              "label": "Κανονικό"
            },
            {
              "id": "lactose_free",
              "label": "Χωρίς λακτόζη"
            },
            {
              "id": "plant",
              "label": "Φυτικό"
            }
          ]
        },
        {
          "id": "ice",
          "label": "Πάγος",
          "required": false,
          "options": [
            {
              "id": "little",
              "label": "Λίγος πάγος"
            },
            {
              "id": "extra",
              "label": "Πολύς πάγος"
            }
          ]
        },
        {
          "id": "caffeine",
          "label": "Καφεΐνη",
          "required": false,
          "options": [
            {
              "id": "decaf",
              "label": "Χωρίς καφεΐνη"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "ice_side",
        "no_straw"
      ]
    },
    {
      "code": "cappuccino",
      "label": "Cappuccino",
      "temperature": "hot",
      "groups": [
        {
          "id": "shots",
          "label": "Δόση",
          "required": true,
          "options": [
            {
              "id": "single",
              "label": "Μονός"
            },
            {
              "id": "double",
              "label": "Διπλός"
            }
          ]
        },
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        },
        {
          "id": "milk",
          "label": "Γάλα",
          "required": true,
          "options": [
            {
              "id": "regular",
              "label": "Κανονικό"
            },
            {
              "id": "lactose_free",
              "label": "Χωρίς λακτόζη"
            },
            {
              "id": "plant",
              "label": "Φυτικό"
            }
          ]
        },
        {
          "id": "foam",
          "label": "Αφρόγαλα",
          "required": false,
          "options": [
            {
              "id": "little",
              "label": "Λίγος αφρός"
            },
            {
              "id": "extra",
              "label": "Έξτρα αφρός"
            }
          ]
        },
        {
          "id": "caffeine",
          "label": "Καφεΐνη",
          "required": false,
          "options": [
            {
              "id": "decaf",
              "label": "Χωρίς καφεΐνη"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "very_hot",
        "lukewarm",
        "cinnamon",
        "cocoa"
      ]
    },
    {
      "code": "freddo_cappuccino",
      "label": "Freddo Cappuccino",
      "temperature": "cold",
      "groups": [
        {
          "id": "shots",
          "label": "Δόση",
          "required": true,
          "options": [
            {
              "id": "single",
              "label": "Μονός"
            },
            {
              "id": "double",
              "label": "Διπλός"
            }
          ]
        },
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        },
        {
          "id": "milk",
          "label": "Γάλα",
          "required": true,
          "options": [
            {
              "id": "regular",
              "label": "Κανονικό"
            },
            {
              "id": "lactose_free",
              "label": "Χωρίς λακτόζη"
            },
            {
              "id": "plant",
              "label": "Φυτικό"
            }
          ]
        },
        {
          "id": "ice",
          "label": "Πάγος",
          "required": false,
          "options": [
            {
              "id": "little",
              "label": "Λίγος πάγος"
            },
            {
              "id": "extra",
              "label": "Πολύς πάγος"
            }
          ]
        },
        {
          "id": "foam",
          "label": "Αφρόγαλα",
          "required": false,
          "options": [
            {
              "id": "little",
              "label": "Λίγος αφρός"
            },
            {
              "id": "extra",
              "label": "Έξτρα αφρός"
            }
          ]
        },
        {
          "id": "caffeine",
          "label": "Καφεΐνη",
          "required": false,
          "options": [
            {
              "id": "decaf",
              "label": "Χωρίς καφεΐνη"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "ice_side",
        "no_straw",
        "cinnamon",
        "cocoa"
      ]
    },
    {
      "code": "greek_coffee",
      "label": "Ελληνικός",
      "temperature": "hot",
      "groups": [
        {
          "id": "shots",
          "label": "Μέγεθος",
          "required": true,
          "options": [
            {
              "id": "single",
              "label": "Μονός"
            },
            {
              "id": "double",
              "label": "Διπλός"
            }
          ]
        },
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "very_hot",
        "lukewarm"
      ]
    },
    {
      "code": "filter_coffee",
      "label": "Φίλτρου",
      "temperature": "hot",
      "groups": [
        {
          "id": "sweetness",
          "label": "Ζάχαρη",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Σκέτος"
            },
            {
              "id": "little",
              "label": "Ολίγη"
            },
            {
              "id": "medium",
              "label": "Μέτριος"
            },
            {
              "id": "sweet",
              "label": "Γλυκός"
            },
            {
              "id": "very_sweet",
              "label": "Πολύ γλυκός"
            }
          ]
        },
        {
          "id": "sweetener",
          "label": "Γλυκαντικό",
          "required": true,
          "visible_when": {
            "group": "sweetness",
            "not_in": [
              "none"
            ]
          },
          "options": [
            {
              "id": "white",
              "label": "Λευκή ζάχαρη"
            },
            {
              "id": "brown",
              "label": "Καστανή ζάχαρη"
            },
            {
              "id": "stevia",
              "label": "Στέβια"
            }
          ]
        },
        {
          "id": "milk",
          "label": "Γάλα",
          "required": true,
          "options": [
            {
              "id": "none",
              "label": "Χωρίς γάλα"
            },
            {
              "id": "regular",
              "label": "Κανονικό"
            },
            {
              "id": "lactose_free",
              "label": "Χωρίς λακτόζη"
            },
            {
              "id": "plant",
              "label": "Φυτικό"
            }
          ]
        },
        {
          "id": "caffeine",
          "label": "Καφεΐνη",
          "required": false,
          "options": [
            {
              "id": "decaf",
              "label": "Χωρίς καφεΐνη"
            }
          ]
        }
      ],
      "quick_notes": [
        "takeaway",
        "very_hot",
        "lukewarm"
      ]
    }
  ]
};
