import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CheckoutResult, JoinApi, RegisterResult, TermsResult } from "../../src/customer/joinApi";
import type { JoinConfig, JoinStep } from "../../src/customer/joinConfig";
import { JoinApp } from "../../src/customer/JoinApp";
import { JOIN_MESSAGES } from "../../src/customer/joinRules";
import { PosterApp } from "../../src/customer/PosterApp";
import { encodeQr } from "../../src/customer/qrEncode";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-07 — DOM tests (jsdom) of the in-store join flow over a fake join-v1 client: register (browser checks,
 * server field codes, the "may already have an account" answer, rate limit, try_later, honeypot), the terms step WITHOUT any
 * marketing choice (owner decision D12), the offer's hand-off, the member / not-a-customer / unavailable pages, and
 * the tablet poster. No network, no browser storage. Round 2: the honeypot is named evc_extra (autocomplete off); round 3 changed only the server caps and the checkout guard (Store API, rounds 3-4); STAGE-08 changed no join UI.
 */

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
const storageWrites: string[] = [];
const BASE = "https://shop.example/prometheus";
const CONFIG: JoinConfig = {
  restBase: `${BASE}/?rest_route=/evangelou-club-customer/v1`,
  nonce: "0123456789",
  step: "register",
  joinUrl: `${BASE}/?evc_club_join=1`,
  memberUrl: `${BASE}/?evc_club_member=1`,
  loginUrl: `${BASE}/wp-login.php?redirect_to=join`,
  logoutUrl: `${BASE}/wp-login.php?action=logout`,
  lostPasswordUrl: `${BASE}/wp-login.php?action=lostpassword`,
  privacyUrl: `${BASE}/privacy/`,
  termsUrl: "",
  appVersion: "t",
};

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  dom.window.scrollTo = () => {};
  for (const area of ["localStorage", "sessionStorage"] as const) {
    const s = dom.window[area];
    for (const m of ["setItem", "removeItem", "clear"] as const) {
      const original = s[m].bind(s);
      Object.defineProperty(s, m, { value: (...args: unknown[]) => (storageWrites.push(`${area}.${m}`), (original as (...a: unknown[]) => unknown)(...args)), configurable: true });
    }
  }
});
after(() => {
  assert.deepEqual(storageWrites, [], "the join flow stores nothing in the browser");
  dom.dispose();
});
beforeEach(async () => {
  await dom.reset();
});

const app = () => dom.document.querySelector('[data-evc-customer="join"]') as HTMLElement;
const text = () => app().textContent ?? "";
const step = () => app().getAttribute("data-evc-join-step");
const input = (id: string) => dom.document.getElementById(id) as HTMLInputElement;
const button = (label: string) => {
  const b = [...dom.document.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};

async function type(el: HTMLInputElement, value: string) {
  await act(async () => {
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, "value")!.set!.call(el, value);
    el.dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  });
}

type Fake = { api: JoinApi; calls: string[]; registered: unknown[] };

function fakeApi(answers: { register?: RegisterResult[]; terms?: TermsResult[]; checkout?: CheckoutResult[] }): Fake {
  const calls: string[] = [];
  const registered: unknown[] = [];
  const next = <T,>(list: T[] | undefined, fallback: T): T => (list && list.length > 0 ? list.shift()! : fallback);
  return {
    calls,
    registered,
    api: {
      register: async (form, honeypot) => {
        calls.push("register");
        registered.push({ ...form, honeypot });
        return next(answers.register, { kind: "error" } as RegisterResult);
      },
      acceptTerms: async () => {
        calls.push("terms");
        return next(answers.terms, { kind: "error" } as TermsResult);
      },
      checkout: async () => {
        calls.push("checkout");
        return next(answers.checkout, { kind: "error" } as CheckoutResult);
      },
    },
  };
}

async function mount(startAt: JoinStep, fake: Fake, config: Partial<JoinConfig> = {}) {
  const visited: string[] = [];
  await dom.render(createElement(JoinApp, { config: { ...CONFIG, ...config, step: startAt }, api: fake.api, navigate: (u: string) => visited.push(u) }));
  await act(async () => {});
  return visited;
}

async function fillValid() {
  await type(input("evc-join-name"), "Ελένη Μαρκοπούλου");
  await type(input("evc-join-phone"), "694 123 4567");
  await type(input("evc-join-email"), "eleni@example.gr");
  await type(input("evc-join-password"), "kafes-sto-limani-7");
  await type(input("evc-join-confirm"), "kafes-sto-limani-7");
}

async function submit() {
  await dom.click(button("Δημιουργία λογαριασμού"));
  await act(async () => {});
}

describe("STAGE-07 join: the account step", () => {
  it("checks the form in the browser first: every field described, the first one focused, nothing sent", async () => {
    const fake = fakeApi({});
    await mount("register", fake);
    assert.equal(step(), "register");
    assert.match(text(), /Βήμα 1 από 3/);
    await submit();
    assert.deepEqual(fake.calls, [], "nothing sent with errors");
    for (const id of ["evc-join-name", "evc-join-phone", "evc-join-email", "evc-join-password", "evc-join-confirm"]) {
      assert.equal(input(id).getAttribute("aria-invalid"), "true", id);
      assert.ok(input(id).getAttribute("aria-describedby")?.includes(`${id}-error`), id);
    }
    assert.ok(dom.document.activeElement === input("evc-join-name"), "the first invalid field is focused");
    assert.match(dom.document.querySelector('[role="status"]')!.textContent ?? "", /Ελέγξτε 5 πεδία/);
    assert.equal(input("evc-join-password").type, "password");
    assert.equal(input("evc-join-password").getAttribute("autocomplete"), "new-password");
    await type(input("evc-join-name"), "Ελένη Μαρκοπούλου");
    assert.equal(input("evc-join-name").getAttribute("aria-invalid"), null, "an error clears while it is corrected");
  });

  it("a new account reloads the join page from the server (never a URL of its own)", async () => {
    const fake = fakeApi({ register: [{ kind: "ok" }] });
    const visited = await mount("register", fake);
    await fillValid();
    await submit();
    assert.deepEqual(fake.calls, ["register"]);
    assert.deepEqual(fake.registered, [{ name: "Ελένη Μαρκοπούλου", phone: "694 123 4567", email: "eleni@example.gr", password: "kafes-sto-limani-7", confirm: "kafes-sto-limani-7", honeypot: "" }]);
    assert.deepEqual(visited, [CONFIG.joinUrl]);
  });

  it("an existing e-mail: the same neutral answer with sign-in and reset links, nothing more", async () => {
    const fake = fakeApi({ register: [{ kind: "check_account" }] });
    const visited = await mount("register", fake);
    await fillValid();
    await submit();
    const notice = dom.document.querySelector('[data-evc-join="notice"]') as HTMLElement;
    assert.ok(notice);
    assert.equal(notice.getAttribute("role"), "alert");
    assert.ok(dom.document.activeElement === notice, "focus on the notice");
    assert.match(notice.textContent ?? "", /Ίσως έχετε ήδη λογαριασμό/);
    assert.doesNotMatch(notice.textContent ?? "", /eleni@example\.gr|υπάρχει ήδη|βρέθηκε/, "the e-mail is not confirmed back");
    const links = [...notice.querySelectorAll("a")].map((a) => a.getAttribute("href"));
    assert.deepEqual(links, [CONFIG.loginUrl, CONFIG.lostPasswordUrl]);
    assert.deepEqual(visited, []);
  });

  it("server field codes come back as the same Greek messages, and other answers are explained", async () => {
    const fake = fakeApi({ register: [{ kind: "invalid", fields: { email: "email_invalid", password: "password_weak" } }, { kind: "rate_limited" }, { kind: "try_later" }, { kind: "not_completed" }, { kind: "error" }] });
    await mount("register", fake);
    await fillValid();
    await submit();
    assert.equal(input("evc-join-email").getAttribute("aria-invalid"), "true");
    assert.match(text(), new RegExp(JOIN_MESSAGES.passwordWeak.slice(0, 20)));
    assert.ok(dom.document.activeElement === input("evc-join-email"));
    for (const phrase of [/Πολλές προσπάθειες/, /δεν είναι δυνατή αυτή τη στιγμή/, /Η εγγραφή δεν ολοκληρώθηκε/, /Δεν ήταν δυνατή η σύνδεση/]) {
      await submit();
      assert.match(text(), phrase);
    }
  });

  it("the honeypot is invisible, unfocusable and sent as typed", async () => {
    const fake = fakeApi({ register: [{ kind: "not_completed" }] });
    await mount("register", fake);
    const pot = input("evc-join-extra");
    assert.equal(pot.tabIndex, -1);
    assert.equal(pot.getAttribute("name"), "evc_extra", "not a name browsers autofill");
    assert.equal(pot.getAttribute("autocomplete"), "off");
    assert.equal(pot.closest('[aria-hidden="true"]') !== null, true);
    await fillValid();
    await type(pot, "https://spam.example");
    await submit();
    assert.equal((fake.registered[0] as { honeypot: string }).honeypot, "https://spam.example");
  });

  it("a double tap sends once", async () => {
    let release: (r: RegisterResult) => void = () => {};
    const calls: string[] = [];
    const api: JoinApi = {
      register: () => {
        calls.push("register");
        return new Promise((resolve) => (release = resolve));
      },
      acceptTerms: async () => ({ kind: "error" }),
      checkout: async () => ({ kind: "error" }),
    };
    const visited: string[] = [];
    await dom.render(createElement(JoinApp, { config: CONFIG, api, navigate: (u: string) => visited.push(u) }));
    await fillValid();
    // Two submits before React re-renders (e.g. Enter pressed twice quickly): the button is not disabled yet.
    const form = dom.document.querySelector("form")!;
    await act(async () => {
      form.dispatchEvent(new dom.window.Event("submit", { bubbles: true, cancelable: true }));
      form.dispatchEvent(new dom.window.Event("submit", { bubbles: true, cancelable: true }));
    });
    await dom.click(button("Μια στιγμή…"));
    assert.equal(button("Μια στιγμή…").disabled, true);
    await act(async () => release({ kind: "ok" }));
    assert.deepEqual(calls, ["register"]);
    assert.deepEqual(visited, [CONFIG.joinUrl]);
  });
});

describe("STAGE-07 join: terms (D12: no marketing consent), offer and the other pages", () => {
  it("the terms step has ONE choice, the Club terms; no newsletter anywhere", async () => {
    const fake = fakeApi({ terms: [{ kind: "ok" }], checkout: [{ kind: "checkout", url: `${BASE}/checkout/?add-to-cart=24435` }] });
    const visited = await mount("terms", fake);
    assert.equal(app().querySelectorAll('input[type="checkbox"]').length, 1);
    assert.doesNotMatch(text(), /Newsletter|newsletter|προσφορές|ενημερώσεις/i);
    assert.match(text(), /Αποδέχομαι τους όρους του Evangelou Club/);
    assert.ok([...app().querySelectorAll("a")].some((a) => a.getAttribute("href") === CONFIG.privacyUrl), "the privacy policy link");
    assert.equal(button("Συνέχεια").disabled, true, "the terms are required");
    await dom.click(app().querySelector('input[type="checkbox"]')!);
    assert.equal(button("Συνέχεια").disabled, false);
    await dom.click(button("Συνέχεια"));
    await act(async () => {});
    assert.equal(step(), "offer");
    assert.ok(dom.document.activeElement === app().querySelector("h1"), "focus moves to the new step");
    assert.match(text(), /20 €/);
    assert.doesNotMatch(text(), /προεπισκόπηση/i, "no preview note in the real flow");
    assert.match(text(), /τραπεζική μεταφορά/);
    await dom.click(button("Πληρωμή 20 €"));
    await act(async () => {});
    assert.deepEqual(fake.calls, ["terms", "checkout"]);
    assert.deepEqual(visited, [`${BASE}/checkout/?add-to-cart=24435`]);
  });

  it("the offer sends an existing member or a pending order to the member area; terms first when needed", async () => {
    const fake = fakeApi({ checkout: [{ kind: "member", url: CONFIG.memberUrl }, { kind: "terms_required" }, { kind: "unavailable" }] });
    const visited = await mount("offer", fake);
    await dom.click(button("Πληρωμή 20 €"));
    await act(async () => {});
    assert.deepEqual(visited, [CONFIG.memberUrl]);
    await dom.click(button("Πληρωμή 20 €"));
    await act(async () => {});
    assert.equal(step(), "terms");
    await dom.reset();
    const visited2 = await mount("offer", fakeApi({ checkout: [{ kind: "unavailable" }] }));
    await dom.click(button("Πληρωμή 20 €"));
    await act(async () => {});
    assert.equal(step(), "unavailable");
    assert.deepEqual(visited2, []);
  });

  it("member, not a customer and unavailable pages", async () => {
    let visited = await mount("member", fakeApi({}));
    assert.match(text(), /Είστε ήδη στο Club/);
    await dom.click(button("Στον λογαριασμό μέλους"));
    assert.deepEqual(visited, [CONFIG.memberUrl]);
    await dom.reset();
    await mount("not_customer", fakeApi({}));
    assert.match(text(), /δεν μπορεί να γίνει μέλος/);
    assert.equal(app().querySelector("a")?.getAttribute("href"), CONFIG.logoutUrl);
    await dom.reset();
    visited = await mount("unavailable", fakeApi({}));
    await dom.click(button("Δοκιμάστε ξανά"));
    assert.deepEqual(visited, [CONFIG.joinUrl]);
  });
});

describe("STAGE-07 tablet poster", () => {
  it("draws ONLY the join URL as a QR, works offline, and reloads on demand", async () => {
    let reloads = 0;
    const joinUrl = `${BASE}/?evc_club_join=1&src=store`;
    await dom.render(createElement(PosterApp, { config: { joinUrl, appVersion: "t" }, reload: () => reloads++, online: () => false }));
    const poster = dom.document.querySelector('[data-evc-customer="poster"]') as HTMLElement;
    const svg = poster.querySelector('[data-evc-qr="join"]') as SVGElement;
    assert.ok(svg);
    assert.equal(svg.getAttribute("aria-label"), "Κωδικός QR για την εγγραφή στο Evangelou Club");
    const n = encodeQr(joinUrl).length;
    assert.equal(svg.getAttribute("viewBox"), `0 0 ${n + 8} ${n + 8}`, "the QR of exactly the join URL");
    const dark = encodeQr(joinUrl).flat().filter(Boolean).length;
    assert.equal((svg.querySelector("path")!.getAttribute("d")!.match(/M/g) ?? []).length, dark);
    assert.match(poster.textContent ?? "", /Χωρίς σύνδεση/);
    assert.ok(poster.querySelector("h1"));
    await dom.click([...poster.querySelectorAll("button")].find((b) => b.textContent?.trim() === "Ανανέωση")!);
    assert.equal(reloads, 1);
    assert.equal(dom.network.fetch.length, 0, "no network");
  });
});

describe("STAGE-08 round 4: the offer's payment is the obvious main action (owner's staging run on a phone)", () => {
  it("«Πληρωμή 20 €» is a full-width filled button; the account link is a quiet secondary link after it", async () => {
    await mount("offer", fakeApi({}));
    const pay = app().querySelector('[data-evc-action="pay"]') as HTMLButtonElement;
    const secondary = app().querySelector('[data-evc-action="secondary"]') as HTMLButtonElement;
    assert.ok(pay && secondary);
    assert.equal(pay.tagName, "BUTTON");
    assert.equal(pay.textContent?.trim(), "Πληρωμή 20 €");
    assert.equal(pay.disabled, false);
    const payClass = pay.className.split(/\s+/);
    for (const c of ["w-full", "bg-[var(--brass)]", "rounded-full", "min-h-14"]) assert.ok(payClass.includes(c), `pay button: ${c}`);
    const secondaryClass = secondary.className.split(/\s+/);
    assert.ok(secondaryClass.includes("t-small") && secondaryClass.includes("underline"), "the account action reads as a small link");
    for (const c of ["w-full", "rounded-full"]) assert.ok(!secondaryClass.some((x) => x === c || x.startsWith("bg-")), `not a filled button: ${c}`);
    assert.ok(pay.compareDocumentPosition(secondary) & dom.window.Node.DOCUMENT_POSITION_FOLLOWING, "payment first, account link after");
    assert.equal(app().querySelectorAll('[data-evc-action="pay"]').length, 1, "one main action");
  });
});

describe("STAGE-08 round 18, owner decision D32: the password fields and the buttons of the join flow", () => {
  it("each password has a show / hide button that keeps the focus and the caret", async () => {
    await mount("register", fakeApi({}));
    for (const id of ["evc-join-password", "evc-join-confirm"]) {
      const field = input(id);
      const toggle = field.parentElement!.querySelector('[data-evc-action="toggle-password"]') as HTMLButtonElement;
      assert.ok(toggle, id);
      assert.equal(toggle.getAttribute("type"), "button", "never submits the form");
      assert.equal(toggle.getAttribute("aria-controls"), id);
      assert.equal(toggle.getAttribute("aria-label"), "Εμφάνιση κωδικού");
      assert.equal(toggle.getAttribute("aria-pressed"), "false");
      await type(field, "kafes-sto-limani-7");
      await dom.focus(field);
      field.setSelectionRange(3, 5);
      const down = new dom.window.MouseEvent("mousedown", { bubbles: true, cancelable: true });
      toggle.dispatchEvent(down);
      assert.equal(down.defaultPrevented, true, "the tap does not take the focus (iOS Safari, Android Chrome keyboards stay open)");
      // A browser may move the caret when the type changes (jsdom does not): the field restores it explicitly.
      const restored: [number | null, number | null][] = [];
      const own = field.setSelectionRange.bind(field);
      field.setSelectionRange = (a: number | null, b: number | null, d?: "forward" | "backward" | "none") => {
        restored.push([a, b]);
        own(a, b, d);
      };
      await dom.click(toggle);
      assert.deepEqual(restored, [[3, 5]], "the caret / selection is put back after the switch");
      assert.equal(input(id).type, "text");
      assert.equal(toggle.getAttribute("aria-label"), "Απόκρυψη κωδικού");
      assert.equal(toggle.getAttribute("aria-pressed"), "true");
      assert.ok(dom.document.activeElement === input(id), "the field keeps the focus");
      assert.deepEqual([input(id).selectionStart, input(id).selectionEnd], [3, 5], "and the caret / selection");
      assert.equal(input(id).value, "kafes-sto-limani-7");
      await dom.click(toggle);
      assert.equal(input(id).type, "password");
      assert.equal(toggle.getAttribute("aria-label"), "Εμφάνιση κωδικού");
    }
    assert.equal(input("evc-join-confirm").getAttribute("autocomplete"), "new-password");
    assert.equal(input("evc-join-confirm").getAttribute("name"), "password_confirm");
  });

  it("different passwords block the send with «Οι κωδικοί δεν ταιριάζουν»", async () => {
    const fake = fakeApi({ register: [{ kind: "ok" }] });
    await mount("register", fake);
    await fillValid();
    await type(input("evc-join-confirm"), "kafes-sto-limani-8");
    await submit();
    assert.deepEqual(fake.calls, [], "nothing sent");
    assert.equal(input("evc-join-confirm").getAttribute("aria-invalid"), "true");
    assert.match(text(), /Οι κωδικοί δεν ταιριάζουν/);
    assert.ok(dom.document.activeElement === input("evc-join-confirm"));
    await type(input("evc-join-confirm"), "kafes-sto-limani-7");
    await submit();
    assert.deepEqual(fake.calls, ["register"]);
  });

  it("the server's refusal of a different confirmation comes back on that field", async () => {
    await mount("register", fakeApi({ register: [{ kind: "invalid", fields: { confirm: "confirm_mismatch" } }] }));
    await fillValid();
    await submit();
    assert.equal(input("evc-join-confirm").getAttribute("aria-invalid"), "true");
    assert.match(text(), /Οι κωδικοί δεν ταιριάζουν/);
  });

  it("«Δημιουργία λογαριασμού» is a real primary button inside the design system, busy while sending; other pages are links drawn as buttons", async () => {
    let release: (r: RegisterResult) => void = () => {};
    const api: JoinApi = { register: () => new Promise((r) => (release = r)), acceptTerms: async () => ({ kind: "error" }) as TermsResult, checkout: async () => ({ kind: "error" }) as CheckoutResult };
    await dom.render(createElement(JoinApp, { config: { ...CONFIG, step: "register" }, api, navigate: () => {} }));
    await act(async () => {});
    assert.ok(app().classList.contains("evc-ds"), "the root carries the design tokens (the button was colourless without them)");
    const submitButton = dom.document.querySelector('[data-evc-action="register"]') as HTMLButtonElement;
    assert.equal(submitButton.tagName, "BUTTON");
    assert.equal(submitButton.getAttribute("type"), "submit");
    assert.match(submitButton.className, /bg-\[var\(--roast\)\]/);
    assert.match(submitButton.className, /w-full/, "full width on a phone");
    assert.match(submitButton.className, /sm:w-auto/);
    const signIn = dom.document.querySelector('[data-evc-action="sign-in"]') as HTMLAnchorElement;
    assert.equal(signIn.getAttribute("href"), CONFIG.loginUrl);
    assert.match(signIn.className, /rounded-full/);
    assert.doesNotMatch(signIn.className, /underline/);
    await fillValid();
    await dom.click(submitButton);
    assert.equal(submitButton.disabled, true, "disabled while sending");
    assert.equal(submitButton.textContent, "Μια στιγμή…");
    assert.equal(dom.document.querySelector("form")?.getAttribute("aria-busy"), "true");
    await act(async () => release({ kind: "error" }));
    assert.equal(submitButton.disabled, false);
    assert.equal(submitButton.textContent, "Δημιουργία λογαριασμού");
  });
});
