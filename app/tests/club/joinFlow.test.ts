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
 * the tablet poster. No network, no browser storage. Round 2: the honeypot is named evc_extra (autocomplete off); round 3 changed only the server caps and the checkout guard.
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
    for (const id of ["evc-join-name", "evc-join-phone", "evc-join-email", "evc-join-password"]) {
      assert.equal(input(id).getAttribute("aria-invalid"), "true", id);
      assert.ok(input(id).getAttribute("aria-describedby")?.includes(`${id}-error`), id);
    }
    assert.ok(dom.document.activeElement === input("evc-join-name"), "the first invalid field is focused");
    assert.match(dom.document.querySelector('[role="status"]')!.textContent ?? "", /Ελέγξτε 4 πεδία/);
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
    assert.deepEqual(fake.registered, [{ name: "Ελένη Μαρκοπούλου", phone: "694 123 4567", email: "eleni@example.gr", password: "kafes-sto-limani-7", honeypot: "" }]);
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
