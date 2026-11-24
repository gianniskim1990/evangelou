import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { CustomerApp, OfflineApp } from "../../src/customer/CustomerApp";
import { parseCustomerConfig } from "../../src/customer/config";
import { InstallHintFlow } from "../../src/customer/pwa/installHint";
import { toLiveView } from "../../src/customer/liveView";
import { Profile } from "../../src/preview/CustomerPreview";
import { joinAction, liveActions } from "../../src/preview/customerView";
import type { CustomerApi, Me, MeResult } from "../../src/customer/customerApi";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-01 — DOM tests (jsdom) of the REAL customer app over a fake /me
 * client: every server answer renders through the approved screens or a
 * plain status page, nothing unknown is shown as paid or as today's coffee,
 * the QR stays the non-redeemable sample, and the preview's notes about
 * fictional data never appear. No network, no browser storage.
 */

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
const storageWrites: string[] = [];
const NOW = Date.parse("2026-10-20T06:00:00Z");
const CONFIG: CustomerConfig = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php?redirect_to=x", logoutUrl: "https://shop.example/wp-login.php?action=logout&_wpnonce=abc", appVersion: "t" };

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
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

const app = () => dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
const text = () => app().textContent ?? "";
const button = (label: string, root: ParentNode = dom.document) => {
  const b = [...root.querySelectorAll("button")].find((x) => x.textContent?.trim() === label);
  assert.ok(b, `button «${label}» exists`);
  return b as HTMLButtonElement;
};

function me(state: Me["state"], paidUntilUtc: string | null = null): Me {
  return { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state, paidUntilUtc };
}

async function mount(results: MeResult[], config: CustomerConfig = CONFIG) {
  let calls = 0;
  const api: CustomerApi = { me: async () => results[Math.min(calls++, results.length - 1)] };
  const visited: string[] = [];
  await dom.render(createElement(CustomerApp, { config, api, now: () => NOW, navigate: (u: string) => visited.push(u) }));
  await act(async () => {});
  return { visited, calls: () => calls };
}

const FICTION = [/φανταστικ/i, /Δείγμα ιστορικού/, /Δείγμα προφίλ/, /Προεπισκόπηση σχεδίου/, /example\.invalid/];
function assertNoPreviewFiction(where: string) {
  for (const re of FICTION) assert.doesNotMatch(text(), re, `${where}: ${re}`);
}

describe("STAGE-01 customer app: membership states from GET /me", () => {
  it("an active member opens on the card: exact end, NO sample QR (round 17), no claim about today's coffee", async () => {
    await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    assert.equal(app().dataset.evcCustomerState, "active");
    assert.ok(app().querySelector('[data-evc-preview="sample-qr"]') === null, "round 17: never the design preview's sample code");
    assert.doesNotMatch(text(), /ΔΕΙΓΜΑ|ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ|δείγμα/);
    assert.ok(app().querySelector('[data-evc-qr-placeholder="not-available"]'), "without a QR flow: «not available yet», words only");
    assert.equal(app().querySelectorAll("[data-evc-qr-placeholder] svg").length, 0, "no pattern of any kind");
    assert.match(text(), /Ο κωδικός QR δεν είναι ακόμη διαθέσιμος/);
    assert.ok(app().querySelector('[aria-label="Κάρτα μέλους Evangelou Club"]'));
    assert.match(text(), /Ελένη Μαρκοπούλου/);
    assert.match(text(), /Ισχύει έως 15 Νοεμβρίου 2026, 12:00/);
    assert.match(text(), /Η συνδρομή σας είναι ενεργή/);
    assert.doesNotMatch(text(), /Ο σημερινός καφές σας περιμένει|Τον απολαύσατε σήμερα/, "today's coffee is unknown");
    assert.match(text(), /Ο κωδικός QR θα εμφανιστεί σε αυτή την κάρτα μόλις ενεργοποιηθεί\./);
    assert.doesNotMatch(text(), /Ο κωδικός QR δεν είναι ακόμη ενεργός/, "the old note was written for the sample code");
    assert.doesNotMatch(text(), /Μέλος από τις/, "no invented member-since date");
    assertNoPreviewFiction("card");

    await dom.click(button("Αρχική"));
    assert.match(text(), /Το ιστορικό καφέδων δεν είναι ακόμη διαθέσιμο/);
    assert.match(text(), /Δεν είναι ακόμη διαθέσιμο/);
    for (const label of ["Σήμερα", "Ιστορικό", "Λογαριασμός"]) {
      await dom.click(button(label, app().querySelector('nav[aria-label="Μενού μέλους"]')!));
      assert.equal(dom.document.activeElement, app().querySelector("h1"), `${label}: focus on the heading`);
      assertNoPreviewFiction(label);
      assert.doesNotMatch(text(), /Ισχύει για \d+ ώρες/, `${label}: no countdown that implies an available coffee`);
    }
    assert.match(text(), /eleni@example\.org/);
    assert.match(text(), /Δεν δόθηκε/, "no invented phone number");
    assert.doesNotMatch(text(), /Newsletter/, "consent is not read, so not shown");
  });

  it("pending, expired, cancelled, none and unverified are explained and never shown as paid", async () => {
    const cases: [Me, RegExp][] = [
      [me("pending_payment"), /Σε αναμονή πληρωμής/],
      [me("expired", "2026-10-16T16:30:00+00:00"), /Έληξε στις 16 Οκτωβρίου 2026, 19:30/],
      [me("cancelled"), /Η συνδρομή ακυρώθηκε/],
      [me("none"), /Δεν έχετε συνδρομή/],
      [me("unverified"), /Η συνδρομή δεν επιβεβαιώθηκε/],
    ];
    for (const [m, phrase] of cases) {
      await dom.reset();
      await mount([{ kind: "ok", me: m }]);
      assert.match(text(), phrase, m.state);
      assert.doesNotMatch(text(), /Η συνδρομή σας είναι ενεργή|Ο σημερινός καφές σας περιμένει|Ισχύει έως/, `${m.state} is never active`);
      assert.ok(app().querySelector('nav[aria-label="Μενού μέλους"]'), `${m.state} opens on the dashboard`);
      assertNoPreviewFiction(m.state);
    }
  });
});

describe("STAGE-08 round 10: «Γίνετε μέλος» for a signed-in customer without a membership", () => {
  const JOIN = "https://shop.example/club/join/";
  const WITH_JOIN: CustomerConfig = { ...CONFIG, joinUrl: JOIN };
  const joinButtons = () => [...app().querySelectorAll('[data-evc-action="join"]')] as HTMLButtonElement[];

  it("state «none» with the join flow on: a primary «Γίνετε μέλος» on the dashboard and on the card, to the join URL", async () => {
    const { visited } = await mount([{ kind: "ok", me: me("none") }], WITH_JOIN);
    assert.match(text(), /Δεν έχετε συνδρομή/);
    let join = joinButtons();
    assert.equal(join.length, 1, "one join button on the dashboard");
    assert.equal(join[0].textContent?.trim(), "Γίνετε μέλος");
    assert.equal(join[0].disabled, false);
    assert.match(join[0].className, /min-h-14/, "a large primary action");
    await dom.click(join[0]);
    assert.deepEqual(visited, [JOIN]);

    await dom.click(button("Άνοιγμα κάρτας"));
    assert.ok(app().querySelector("article[aria-label]") !== null, "the card screen");
    join = joinButtons();
    assert.equal(join.length, 1, "one join button on the card screen");
    assert.equal(join[0].textContent?.trim(), "Γίνετε μέλος");
    await dom.click(join[0]);
    assert.deepEqual(visited, [JOIN, JOIN]);
    assertNoPreviewFiction("none with join");
  });

  it("state «none» without a join URL (onboarding off): nothing new is shown", async () => {
    await mount([{ kind: "ok", me: me("none") }]);
    assert.match(text(), /Δεν έχετε συνδρομή/);
    assert.equal(joinButtons().length, 0, "dashboard: no join button");
    assert.doesNotMatch(text(), /Γίνετε μέλος/);
    await dom.click(button("Άνοιγμα κάρτας"));
    assert.equal(joinButtons().length, 0, "card: no join button");
    assert.doesNotMatch(text(), /Γίνετε μέλος/);
  });

  it("every other state never offers «Γίνετε μέλος», even with the join flow on", async () => {
    for (const m of [me("active", "2026-11-15T10:00:00+00:00"), me("pending_payment"), me("expired", "2026-10-16T16:30:00+00:00"), me("cancelled"), me("unverified")]) {
      await dom.reset();
      await mount([{ kind: "ok", me: m }], WITH_JOIN);
      if (app().querySelector('nav[aria-label="Μενού μέλους"]') === null) await dom.click(button("Αρχική"));
      for (const where of ["dashboard", "card"]) {
        assert.equal(joinButtons().length, 0, `${m.state} ${where}: no join button`);
        assert.doesNotMatch(text(), /Γίνετε μέλος/, `${m.state} ${where}`);
        if (where === "dashboard") await dom.click(button("Άνοιγμα κάρτας"));
      }
    }
  });
});

describe("STAGE-08 round 11, owner decision D27: renewal from the member card", () => {
  const JOIN = "https://shop.example/club/join/";
  const WITH_JOIN: CustomerConfig = { ...CONFIG, joinUrl: JOIN };
  const actions = (kind: string) => [...app().querySelectorAll(`[data-evc-action="${kind}"]`)] as HTMLButtonElement[];
  const note = () => app().querySelector('[data-evc-note="payment"]');
  const toDashboard = async () => {
    if (app().querySelector('nav[aria-label="Μενού μέλους"]') === null) await dom.click(button("Αρχική"));
  };

  it("EXPIRED: «Ανανέωση 20 €» on the dashboard and the card opens the join flow, the same payment as joining", async () => {
    const { visited } = await mount([{ kind: "ok", me: me("expired", "2026-10-16T16:30:00+00:00") }], WITH_JOIN);
    await toDashboard();
    for (const where of ["dashboard", "card"]) {
      const renew = actions("renew");
      assert.equal(renew.length, 1, `${where}: one renewal button`);
      assert.equal(renew[0].textContent?.trim(), "Ανανέωση 20 €");
      assert.equal(renew[0].disabled, false, `${where}: it works`);
      assert.equal(renew[0].getAttribute("title"), null, `${where}: no preview tooltip`);
      assert.match(renew[0].className, /min-h-14/, `${where}: a large target`);
      await dom.click(renew[0]);
      assert.equal(visited.at(-1), JOIN, `${where}: to the join flow`);
      assert.ok(note() === null, `${where}: no extra text`);
      if (where === "dashboard") await dom.click(button("Άνοιγμα κάρτας"));
    }
    assert.deepEqual(visited, [JOIN, JOIN]);
  });

  it("EXPIRED without the join flow: no button at all, a plain line to renew at the shop", async () => {
    await mount([{ kind: "ok", me: me("expired", "2026-10-16T16:30:00+00:00") }]);
    await toDashboard();
    for (const where of ["dashboard", "card"]) {
      assert.equal(actions("renew").length, 0, where);
      assert.equal([...app().querySelectorAll("button")].some((b) => /Ανανέωση/.test(b.textContent ?? "")), false, `${where}: no renewal button`);
      assert.equal(note()?.textContent, "Για να ανανεώσετε τη συνδρομή σας, μιλήστε με το κατάστημα.", where);
      if (where === "dashboard") await dom.click(button("Άνοιγμα κάρτας"));
    }
  });

  it("ACTIVE: no renewal before the end of the paid month, anywhere (dashboard, card, account)", async () => {
    await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }], WITH_JOIN);
    for (const screen of ["card", "Αρχική", "Λογαριασμός"]) {
      if (screen === "Αρχική") await dom.click(button("Αρχική"));
      if (screen === "Λογαριασμός") await dom.click(button("Λογαριασμός", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
      assert.equal(actions("renew").length, 0, screen);
      assert.equal([...app().querySelectorAll("button")].some((b) => /Ανανέωση/.test(b.textContent ?? "")), false, `${screen}: no renewal button`);
    }
  });

  it("PENDING: the disabled «Ολοκλήρωση πληρωμής» is gone; plain information about the bank transfer instead", async () => {
    const { visited } = await mount([{ kind: "ok", me: me("pending_payment") }], WITH_JOIN);
    await toDashboard();
    for (const where of ["dashboard", "card"]) {
      assert.equal([...app().querySelectorAll("button")].some((b) => /Ολοκλήρωση πληρωμής|Ανανέωση/.test(b.textContent ?? "")), false, `${where}: no payment button`);
      const n = note();
      assert.ok(n, `${where}: the information`);
      assert.equal(n!.getAttribute("role"), "status");
      assert.match(n!.textContent ?? "", /περιμένει την τραπεζική μεταφορά/);
      assert.match(n!.textContent ?? "", /στο μήνυμα επιβεβαίωσης της παραγγελίας/);
      assert.match(n!.textContent ?? "", /μόλις το κατάστημα επιβεβαιώσει/);
      assert.ok(n!.querySelector("a") === null, "no link: the shop's order page does not show the bank details");
      if (where === "dashboard") await dom.click(button("Άνοιγμα κάρτας"));
    }
    assert.deepEqual(visited, []);
  });

  it("CANCELLED and UNVERIFIED: no payment or renewal button and no new text (the shop decides; blocks_new_order refuses unverified)", async () => {
    for (const m of [me("cancelled"), me("unverified")]) {
      await dom.reset();
      const { visited } = await mount([{ kind: "ok", me: m }], WITH_JOIN);
      for (const where of ["dashboard", "card"]) {
        assert.equal(actions("renew").length + actions("pay").length + actions("join").length, 0, `${m.state} ${where}`);
        assert.ok(note() === null, `${m.state} ${where}`);
        if (where === "dashboard") await dom.click(button("Άνοιγμα κάρτας"));
      }
      assert.deepEqual(visited, []);
    }
  });

  it("no live control carries the preview tooltip, in any state or screen", async () => {
    const states = [me("active", "2026-11-15T10:00:00+00:00"), me("pending_payment"), me("expired", "2026-10-16T16:30:00+00:00"), me("cancelled"), me("none"), me("unverified")];
    for (const config of [CONFIG, WITH_JOIN]) {
      for (const m of states) {
        await dom.reset();
        await mount([{ kind: "ok", me: m }], config);
        const check = (where: string) => {
          for (const el of app().querySelectorAll("[title]")) assert.doesNotMatch(el.getAttribute("title") ?? "", /προεπισκόπηση/i, `${m.state} ${where}`);
          assert.doesNotMatch(text(), /προεπισκόπηση/i, `${m.state} ${where}`);
        };
        check("first screen");
        await toDashboard();
        for (const label of ["Αρχική", "Σήμερα", "Ιστορικό", "Λογαριασμός"]) {
          await dom.click(button(label, app().querySelector('nav[aria-label="Μενού μέλους"]')!));
          check(label);
        }
        assert.equal([...app().querySelectorAll("button")].some((b) => b.textContent?.trim() === "Επεξεργασία στοιχείων"), false, "the preview-only edit button is not on the live app");
      }
    }
  });
});

describe("STAGE-08 round 11, D27: the rules behind the buttons (pure)", () => {
  it("liveActions: join only without a membership, renew only when expired, both only with a join URL", () => {
    const JOIN = "https://shop.example/club/join/";
    const visited: string[] = [];
    const signOut = () => {};
    for (const state of ["active", "pending", "cancelled", "unverified"] as const) {
      assert.deepEqual(Object.keys(liveActions(state, JOIN, (u) => visited.push(u), signOut)), ["signOut"], state);
    }
    const none = liveActions("none", JOIN, (u) => visited.push(u), signOut);
    assert.deepEqual(Object.keys(none).sort(), ["join", "signOut"]);
    const expired = liveActions("expired", JOIN, (u) => visited.push(u), signOut);
    assert.deepEqual(Object.keys(expired).sort(), ["renew", "signOut"]);
    none.join!();
    expired.renew!();
    assert.deepEqual(visited, [JOIN, JOIN]);
    for (const state of ["none", "expired"] as const) assert.deepEqual(Object.keys(liveActions(state, "", () => {}, signOut)), ["signOut"], `${state} without a join URL`);
  });

  it("joinAction: «Γίνετε μέλος» only for «none», even when a join action is given", () => {
    const join = () => {};
    for (const m of [me("active", "2026-11-15T10:00:00+00:00"), me("pending_payment"), me("expired", "2026-10-16T16:30:00+00:00"), me("cancelled"), me("unverified")]) {
      assert.equal(joinAction(toLiveView(m, NOW), { join }), null, m.state);
    }
    assert.deepEqual(joinAction(toLiveView(me("none"), NOW), { join }), { label: "Γίνετε μέλος", run: join });
    assert.equal(joinAction(toLiveView(me("none"), NOW), {}), null, "no join flow, no button");
  });

  it("the account screen never offers an early renewal on the live app, even if an action were given", async () => {
    const view = { ...toLiveView(me("active", "2026-11-15T10:00:00+00:00"), NOW) };
    await dom.render(createElement(Profile, { view, go: () => {}, actions: { renew: () => {}, signOut: () => {} } }));
    assert.equal([...dom.document.querySelectorAll("button")].some((b) => /Ανανέωση/.test(b.textContent ?? "")), false);
    await dom.render(createElement(Profile, { view: { ...view, live: false }, go: () => {}, actions: { renew: () => {}, signOut: () => {} } }));
    assert.ok([...dom.document.querySelectorAll("button")].some((b) => /Ανανέωση τώρα/.test(b.textContent ?? "")), "the design preview keeps its sample");
  });
});

describe("STAGE-08 round 11 (C2): a bad optional join URL never breaks the member app", () => {
  it("is dropped: the app starts and «Γίνετε μέλος» is simply not shown", async () => {
    const raw = { restBase: "https://shop.example/wp-json/evangelou-club-customer/v1", homePath: "/", nonce: "0123456789", loginUrl: "https://shop.example/wp-login.php", logoutUrl: "https://shop.example/wp-login.php?action=logout", joinUrl: "https://evil.example/club/join/", appVersion: "t" };
    const parsed = parseCustomerConfig(JSON.stringify(raw), "https://shop.example");
    assert.ok(parsed.ok, "the member app starts");
    assert.equal(parsed.config.joinUrl, "");
    const { visited } = await mount([{ kind: "ok", me: me("none") }], parsed.config);
    assert.match(text(), /Δεν έχετε συνδρομή/);
    assert.equal(app().querySelectorAll('[data-evc-action="join"]').length, 0);
    await mount([{ kind: "ok", me: me("expired", "2026-10-16T16:30:00+00:00") }], parsed.config);
    assert.equal(app().querySelectorAll('[data-evc-action="renew"]').length, 0, "no renewal link either");
    assert.deepEqual(visited, []);
  });
});

describe("STAGE-08 round 11 (D26): «Εγκατάσταση στο κινητό» in the member area", () => {
  class Target {
    readonly fns = new Map<string, ((e: Event) => void)[]>();
    addEventListener(type: string, fn: (e: Event) => void) {
      this.fns.set(type, [...(this.fns.get(type) ?? []), fn]);
    }
    emit(type: string, e: unknown) {
      for (const fn of this.fns.get(type) ?? []) fn(e as Event);
    }
  }
  const hint = () => app().querySelector("[data-evc-install]");

  async function mountWithHint(result: MeResult, ios: boolean, target: Target, stored: Map<string, string>) {
    const flag = { get: () => stored.get("x") === "1", set: () => void stored.set("x", "1") };
    const installHint = new InstallHintFlow({ standalone: () => false, ios, flag }, target);
    const api: CustomerApi = { me: async () => result };
    await dom.render(createElement(CustomerApp, { config: CONFIG, api, now: () => NOW, navigate: () => {}, installHint }));
    await act(async () => {});
    return installHint;
  }

  it("Android: shown below the member area once the browser offers it; «Όχι τώρα» closes it for good", async () => {
    const target = new Target();
    const stored = new Map<string, string>();
    await mountWithHint({ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }, false, target, stored);
    assert.ok(hint() === null, "nothing before the browser's event");
    let prompted = 0;
    await act(async () => target.emit("beforeinstallprompt", { preventDefault() {}, prompt: async () => void prompted++, userChoice: Promise.resolve({ outcome: "accepted" }) }));
    assert.equal(hint()?.getAttribute("data-evc-install"), "prompt");
    assert.match(hint()!.textContent ?? "", /Εγκατάσταση στο κινητό/);
    assert.ok(app().querySelector("article")!.compareDocumentPosition(hint()!) & 4, "after the card, never over the QR");
    await dom.click(button("Όχι τώρα", hint()!));
    assert.ok(hint() === null);
    assert.equal(stored.get("x"), "1");
    assert.equal(prompted, 0);
  });

  it("Android: «Εγκατάσταση» shows the browser's own prompt", async () => {
    const target = new Target();
    await mountWithHint({ kind: "ok", me: me("none") }, false, target, new Map());
    let prompted = 0;
    await act(async () => target.emit("beforeinstallprompt", { preventDefault() {}, prompt: async () => void prompted++, userChoice: Promise.resolve({ outcome: "accepted" }) }));
    await dom.click(button("Εγκατάσταση", hint()!));
    await act(async () => {});
    assert.equal(prompted, 1);
    assert.ok(hint() === null);
  });

  it("iPhone: the Safari steps; «Εντάξει» closes them; never on the sign-in or error pages", async () => {
    const stored = new Map<string, string>();
    await mountWithHint({ kind: "ok", me: me("pending_payment") }, true, new Target(), stored);
    assert.match(hint()!.textContent ?? "", /«Κοινή χρήση» και μετά «Προσθήκη στην οθόνη Αφετηρίας»/);
    await dom.click(button("Εντάξει", hint()!));
    assert.ok(hint() === null);
    for (const r of [{ kind: "signed_out" }, { kind: "error" }, { kind: "unavailable" }, { kind: "forbidden" }] as MeResult[]) {
      await dom.reset();
      await mountWithHint(r, true, new Target(), new Map());
      assert.ok(hint() === null, r.kind);
    }
  });
});

describe("STAGE-08 round 11 (D26): the offline page", () => {
  it("says there is no connection and offers a retry, knowing nothing about the member", async () => {
    let reloads = 0;
    await dom.render(createElement(OfflineApp, { reload: () => void reloads++ }));
    const root = dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
    assert.equal(root.dataset.evcCustomerState, "offline");
    assert.match(root.textContent ?? "", /Δεν υπάρχει σύνδεση/);
    await dom.click(button("Δοκιμάστε ξανά", root));
    assert.equal(reloads, 1);
  });
});

describe("STAGE-01 customer app: signed out, not a customer, unavailable, errors", () => {
  it("signed out: a native WordPress login button, no member data", async () => {
    const { visited } = await mount([{ kind: "signed_out" }]);
    assert.match(text(), /Συνδεθείτε με το e-mail και τον κωδικό/);
    assert.equal(app().querySelectorAll("input").length, 0, "no password field in the app: WordPress login only");
    await dom.click(button("Σύνδεση"));
    assert.deepEqual(visited, [CONFIG.loginUrl]);
  });

  it("not a customer account: explained, with sign-out", async () => {
    const { visited } = await mount([{ kind: "forbidden" }]);
    assert.match(text(), /Δεν είναι λογαριασμός μέλους/);
    await dom.click(button("Αποσύνδεση"));
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
  });

  it("source unavailable: no membership is guessed; retry asks the server again", async () => {
    const { calls } = await mount([{ kind: "unavailable" }, { kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    assert.equal(app().dataset.evcCustomerState, "unavailable");
    assert.match(text(), /Δεν μπορούμε να ελέγξουμε τη συνδρομή σας/);
    assert.doesNotMatch(text(), /ενεργή|Ελένη/);
    assert.equal(app().querySelector('[data-evc-preview="sample-qr"]'), null, "no card without a confirmed answer");
    await dom.click(button("Δοκιμάστε ξανά"));
    await act(async () => {});
    assert.equal(calls(), 2);
    assert.equal(app().dataset.evcCustomerState, "active");
  });

  it("errors: retry, never a membership", async () => {
    await mount([{ kind: "error" }]);
    assert.match(text(), /Δεν φορτώθηκε ο λογαριασμός σας/);
    assert.equal(app().querySelector('[data-evc-preview="sample-qr"]'), null);
  });

  it("sign-out from the profile uses WordPress's own logout", async () => {
    const { visited } = await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    await dom.click(button("Αρχική"));
    await dom.click(button("Λογαριασμός", app().querySelector('nav[aria-label="Μενού μέλους"]')!));
    await dom.click(button("Αποσύνδεση"));
    assert.deepEqual(visited, [CONFIG.logoutUrl]);
    assert.equal(dom.network.fetch.length + dom.network.xhr.length + dom.network.beacon.length + dom.network.websocket.length, 0);
    assert.deepEqual(storageWrites, []);
  });
});

describe("STAGE-08 round 4: the customer card logo is a bundled asset (staging showed a broken image)", () => {
  it("the logo is not a site-root path, which the WordPress pages cannot serve", async () => {
    await mount([{ kind: "ok", me: me("active", "2026-11-15T10:00:00+00:00") }]);
    const logos = [...app().querySelectorAll('img[alt="Ευαγγέλου"]')] as HTMLImageElement[];
    assert.ok(logos.length >= 1, "the card shows the logo");
    for (const img of logos) {
      const src = img.getAttribute("src") ?? "";
      assert.notEqual(src, "/logo-evaggelou-color.png", "not the Vercel public/ path");
      assert.doesNotMatch(src, /^\//, "never a site-root path");
      assert.match(src, /logo-evaggelou-color[^/]*\.png$/, "the bundled (hashed) asset");
    }
  });

  it("no image in the real customer app graph is loaded from a site-root path", async () => {
    const { readFileSync } = await import("node:fs");
    const { join } = await import("node:path");
    const appDir = process.env.EVC_APP_DIR ?? process.cwd();
    for (const file of ["src/preview/ds.tsx", "src/customer/CustomerApp.tsx", "src/customer/JoinApp.tsx", "src/customer/PosterApp.tsx", "src/customer/MemberQrPanel.tsx"]) {
      const source = readFileSync(join(appDir, file), "utf8");
      assert.doesNotMatch(source, /src=["'`]\//, `${file}: no src="/…"`);
    }
  });
});
