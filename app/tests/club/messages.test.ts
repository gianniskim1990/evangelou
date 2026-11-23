import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import type { CustomerConfig } from "../../src/customer/config";
import { parseCustomerConfig } from "../../src/customer/config";
import { CustomerApp } from "../../src/customer/CustomerApp";
import type { CustomerApi, MeResult } from "../../src/customer/customerApi";
import { MemberMessagesFlow } from "../../src/customer/memberMessages";
import { createCustomerMessagesApi, isMessageId, messageIdFromHash, parseMessage, parseMessageList, type CustomerMessagesApi, type MessageRead, type MessagesList } from "../../src/customer/messagesApi";
import { renderRichText, richHref, richImage, richStyle } from "../../src/customer/richText";
import { clickTarget, messageOfTag, notificationFor, parsePushPayload } from "../../src/customer/pwa/swPush";
import { createDom, type DomHarness } from "../staff/support/dom";

/*
 * STAGE-08 round 18, owner decision D30 (2026-11-23): rich messages inside the member app.
 *   - the client of GET /me/messages and /me/messages/<id> (strict parsing, transport rules);
 *   - the allowlist renderer (never HTML into the page; scripts, handlers, other origins and unsafe styles dropped);
 *   - the service worker's rules: the payload's message number, the tag, the tap's same-origin in-scope address;
 *   - the member app: «Μηνύματα» list, the message view, a notification tap (#message=<id>) opening the message.
 */

const ORIGIN = "https://shop.example";
const CONFIG: CustomerConfig = { restBase: `${ORIGIN}/wp-json/evangelou-club-customer/v1`, nonce: "0123456789", loginUrl: `${ORIGIN}/wp-login.php`, logoutUrl: `${ORIGIN}/wp-login.php?action=logout&_wpnonce=abc`, appVersion: "t", clubMessages: true };
const SENT = "2026-11-23T08:30:00Z";
const entry = (id: number, unread = true) => ({ id, title: `Μήνυμα ${id}`, summary: `Σύνοψη ${id}`, sent_at: SENT, unread });

let dom: DomHarness;
let act: (fn: () => Promise<void>) => Promise<void>;
// react-dom must load after the DOM globals exist (tests/staff/support/dom.ts): imported in before().
let renderToStaticMarkup: (node: unknown) => string;

before(async () => {
  dom = await createDom();
  act = (await import("react")).act as unknown as typeof act;
  renderToStaticMarkup = (await import("react-dom/server")).renderToStaticMarkup as unknown as typeof renderToStaticMarkup;
  dom.window.scrollTo = () => {};
  for (const key of ["DOMParser"] as const) {
    Object.defineProperty(globalThis, key, { value: (dom.window as unknown as Record<string, unknown>)[key], configurable: true, writable: true });
  }
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
  dom.window.history.replaceState(null, "", "/club-member/");
});

describe("round 18 (D30): the messages client parses strictly", () => {
  it("the list: newest first, exact keys, counts that add up; anything else is refused", () => {
    const ok = { result: "ok", messages: [entry(9), entry(4, false)], unread: 1 };
    assert.deepEqual(parseMessageList(ok), { kind: "ok", unread: 1, messages: [{ id: 9, title: "Μήνυμα 9", summary: "Σύνοψη 9", sentAt: SENT, unread: true }, { id: 4, title: "Μήνυμα 4", summary: "Σύνοψη 4", sentAt: SENT, unread: false }] });
    for (const bad of [
      { ...ok, unread: 2 },
      { ...ok, messages: [entry(4, false), entry(9)] },
      { ...ok, messages: [entry(9), entry(9, false)] },
      { ...ok, extra: 1 },
      { ...ok, messages: [{ ...entry(9), html: "<p>x</p>" }] },
      { ...ok, messages: [{ ...entry(9), id: 0 }] },
      { ...ok, messages: [{ ...entry(9), id: 2147483648 }] },
      { ...ok, messages: [{ ...entry(9), id: "9" }] },
      { ...ok, messages: [{ ...entry(9), title: "α".repeat(51) }] },
      { ...ok, messages: [{ ...entry(9), summary: "β".repeat(151) }] },
      { ...ok, messages: [{ ...entry(9), sent_at: "yesterday" }] },
      { ...ok, messages: Array.from({ length: 51 }, (_, i) => entry(100 - i, false)), unread: 0 },
    ]) {
      assert.equal(parseMessageList(bad), null, JSON.stringify(bad).slice(0, 80));
    }
  });

  it("one message: the id asked for, the html as a bounded string", () => {
    const body = { result: "ok", message: { id: 9, title: "Τ", summary: "Σ", sent_at: SENT, html: "<p>x</p>" } };
    assert.deepEqual(parseMessage(body, 9), { kind: "ok", message: { id: 9, title: "Τ", summary: "Σ", sentAt: SENT, html: "<p>x</p>" } });
    assert.equal(parseMessage(body, 8), null, "another message than the one asked for");
    assert.equal(parseMessage({ ...body, message: { ...body.message, html: "x".repeat(20001) } }, 9), null);
    assert.equal(parseMessage({ ...body, message: { ...body.message, url: "https://evil.example" } }, 9), null);
  });

  it("the transport: same origin, nonce, no-store, no redirects; answers mapped; an id is digits only", async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    const answers: [number, unknown][] = [];
    const fetchImpl = (async (url: string, init: RequestInit) => {
      calls.push({ url, init });
      const [status, body] = answers.shift() ?? [500, null];
      return { status, headers: { get: () => null }, json: async () => body };
    }) as unknown as typeof fetch;
    const api = createCustomerMessagesApi(CONFIG, { get: () => "0123456789", update: () => {} }, fetchImpl, 1000);
    answers.push([200, { result: "ok", messages: [], unread: 0 }]);
    assert.deepEqual(await api.list(), { kind: "ok", messages: [], unread: 0 });
    assert.equal(calls[0].url, `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/messages`);
    assert.equal(calls[0].init.cache, "no-store");
    assert.equal(calls[0].init.redirect, "error");
    assert.equal(calls[0].init.credentials, "same-origin");
    assert.equal((calls[0].init.headers as Record<string, string>)["X-WP-Nonce"], "0123456789");
    answers.push([404, { error: { code: "message_not_found" } }]);
    assert.deepEqual(await api.get(5), { kind: "not_found" });
    assert.equal(calls[1].url, `${ORIGIN}/wp-json/evangelou-club-customer/v1/me/messages/5`);
    const before = calls.length;
    assert.deepEqual(await api.get(1.5), { kind: "not_found" });
    assert.deepEqual(await api.get(-1), { kind: "not_found" });
    assert.equal(calls.length, before, "an id that is not a message number is never sent");
    for (const [status, body, kind] of [
      [404, { error: { code: "not_a_member" } }, "not_member"],
      [403, { error: { code: "member_disabled" } }, "member_disabled"],
      [404, { code: "rest_no_route" }, "off"],
      [401, null, "signed_out"],
      [403, null, "forbidden"],
      [503, { result: "unavailable" }, "unavailable"],
      [500, null, "error"],
      [200, { result: "ok" }, "error"],
    ] as [number, unknown, string][]) {
      answers.push([status, body]);
      assert.equal((await api.list()).kind, kind, `${status} ${JSON.stringify(body)}`);
    }
  });

  it("«#message=<id>» only; and the config flag is the boolean true only", () => {
    assert.equal(messageIdFromHash("#message=12"), 12);
    for (const bad of ["#message=0", "#message=012", "#message=12&x=1", "#message=-1", "#message=99999999999", "#msg=12", "message=12", "#message=1e3", "", null]) {
      assert.equal(messageIdFromHash(bad), null, String(bad));
    }
    assert.equal(isMessageId(2147483647), true);
    assert.equal(isMessageId(2147483648), false);
    const raw = (v: unknown) => JSON.stringify({ restBase: CONFIG.restBase, homePath: "/", nonce: "0123456789", loginUrl: CONFIG.loginUrl, logoutUrl: CONFIG.logoutUrl, appVersion: "t", clubMessages: v });
    const parsed = (v: unknown) => {
      const r = parseCustomerConfig(raw(v), ORIGIN);
      assert.ok(r.ok);
      return r.config.clubMessages;
    };
    assert.equal(parsed(true), true);
    for (const v of [1, "true", "yes", null, false]) assert.equal(parsed(v), false, String(v));
  });
});

describe("round 18 (D30): the formatted body is drawn by an allowlist, never as HTML", () => {
  const draw = (html: string) => renderToStaticMarkup(createElement("div", null, ...renderRichText(html, ORIGIN)));

  it("keeps the formatting the editor writes", () => {
    const out = draw('<h2 style="color: #e03e2d">Νέα γεύση</h2><p>Δοκιμάστε <strong>σήμερα</strong> το <em>νέο</em> <u>γλυκό</u>.</p><ul><li style="text-align: center">ένα</li></ul><ol><li>δύο</li></ol><blockquote>απόσπασμα</blockquote><hr><p><span style="font-size: 18px; text-decoration: line-through">μέγεθος</span><br>γραμμή</p>');
    for (const want of ['<h2 style="color:#e03e2d">Νέα γεύση</h2>', "<strong>σήμερα</strong>", "<em>νέο</em>", "<u>γλυκό</u>", '<li style="text-align:center">ένα</li>', "<ol><li>δύο</li></ol>", "<blockquote>απόσπασμα</blockquote>", "<hr/>", '<span style="font-size:18px;text-decoration:line-through">μέγεθος</span>', "<br/>"]) {
      assert.ok(out.includes(want), `${want} in ${out}`);
    }
  });

  it("drops scripts, handlers, frames, forms, other origins, unsafe links and styles", () => {
    const out = draw(
      '<p onclick="alert(1)" class="x" id="y">α<script>alert(1)</script></p><iframe src="https://evil.example"></iframe><img src="https://evil.example/t.png" alt="ξένη"><img src="/wp-content/uploads/a.jpg" alt="δική μας" width="600" height="99999" onerror="x()" srcset="https://evil.example/b.jpg 2x">' +
        '<a href="javascript:alert(1)">κακό</a><a href="https://evangelou.gr/x" target="_self" onmouseover="x()">καλό</a><a href="mailto:club@evangelou.gr">γράψτε</a><p style="position: fixed; background: url(https://evil.example/x); color: red; color: #0f0">β</p><form action="https://evil.example"><input name="p"></form><svg><circle /></svg><!-- σχόλιο -->',
    );
    for (const bad of ["onclick", "script", "alert(1)<", "iframe", "evil.example", "onerror", "srcset", "javascript:", "onmouseover", "position", "background", "form", "<input", "svg", "class=", 'id="y"', "σχόλιο", "99999", "_self"]) {
      assert.ok(!out.includes(bad), `${bad} not in ${out}`);
    }
    assert.ok(out.includes(`<img src="${ORIGIN}/wp-content/uploads/a.jpg" alt="δική μας" width="600"`), out);
    assert.ok(out.includes('<a href="https://evangelou.gr/x" target="_blank" rel="noopener noreferrer nofollow">καλό</a>'), out);
    assert.ok(out.includes('<a href="mailto:club@evangelou.gr" target="_blank" rel="noopener noreferrer nofollow">γράψτε</a>'), out);
    assert.ok(out.includes("<span>κακό</span>"), "an unsafe link keeps its text only");
    assert.ok(out.includes('<p style="color:#0f0">β</p>'), out);
  });

  it("the rules one by one, and a body that is too deep stays bounded", () => {
    assert.deepEqual(richStyle("color: rgb(1, 2, 3); text-align: justify"), { color: "rgb(1, 2, 3)", textAlign: "justify" });
    assert.equal(richStyle("color: red; font-size: 400px; width: 100px"), null);
    assert.equal(richHref("https://evangelou.gr/a b"), null);
    assert.equal(richHref("//evil.example"), null);
    assert.equal(richHref("data:text/html,x"), null);
    assert.equal(richHref("tel:+302510000000"), "tel:+302510000000");
    assert.equal(richImage(`${ORIGIN}/wp-content/uploads/../../wp-config.php`, ORIGIN), null);
    assert.equal(richImage("https://evil.example/x.png", ORIGIN), null);
    assert.equal(richImage("data:image/png;base64,AAAA", ORIGIN), null);
    assert.equal(richImage("/wp-content/uploads/x.png", ORIGIN), `${ORIGIN}/wp-content/uploads/x.png`);
    const deep = "<span>".repeat(200) + "βαθύ" + "</span>".repeat(200);
    assert.ok(draw(deep).includes("βαθύ"), "the text survives");
    assert.ok((draw(deep).match(/<span>/g) ?? []).length <= 24, "the depth is bounded");
    assert.deepEqual(renderRichText("", ORIGIN), []);
    assert.deepEqual(renderRichText("<p>x</p>", ORIGIN, null), [], "no parser: nothing drawn");
  });
});

describe("round 18 (D30): the service worker opens the checked message, never an address from the payload", () => {
  const scope = `${ORIGIN}/club-member/`;
  it("the payload's message number is checked; the tag carries it; anything else is ignored", () => {
    assert.deepEqual(parsePushPayload('{"title":"Τ","body":"Κ","message":12}'), { title: "Τ", body: "Κ", message: 12 });
    for (const bad of ['"12"', "0", "-3", "1.5", "2147483648", '{"x":1}', "null", '"https://evil.example/"']) {
      assert.deepEqual(parsePushPayload(`{"title":"Τ","body":"Κ","message":${bad}}`), { title: "Τ", body: "Κ" }, bad);
    }
    assert.deepEqual(parsePushPayload('{"title":"Τ","body":"Κ","url":"https://evil.example"}'), { title: "Τ", body: "Κ" });
    assert.equal(notificationFor({ title: "Τ", body: "Κ", message: 12 }, "").options.tag, "evc-message-12");
    assert.equal(notificationFor({ title: "Τ", body: "Κ" }, "").options.tag, undefined);
    assert.equal(messageOfTag("evc-message-12"), 12);
    for (const bad of ["evc-message-0", "evc-message-12x", "other-12", "evc-message-99999999999", "", undefined]) assert.equal(messageOfTag(bad), null, String(bad));
  });

  it("the tap: the scope + «#message=<id>», an open window is sent there", () => {
    assert.deepEqual(clickTarget([], scope, 12), { open: `${scope}#message=12` });
    assert.deepEqual(clickTarget([`${ORIGIN}/club-member/`], scope, 12), { focus: 0, navigate: `${scope}#message=12` });
    assert.deepEqual(clickTarget([`${ORIGIN}/club-member/`], scope, null), { focus: 0 });
    assert.deepEqual(clickTarget([], scope, 0), { open: scope }, "an unchecked number is ignored");
    assert.deepEqual(clickTarget(["https://evil.example/club-member/"], scope, 12), { open: `${scope}#message=12` });
  });
});

describe("round 18 (D30): the flow", () => {
  it("lists, opens (marking it and the older ones read), keeps a list on a passing failure, hides when refused", async () => {
    const lists: MessagesList[] = [{ kind: "ok", messages: [{ id: 9, title: "Τ9", summary: "Σ9", sentAt: SENT, unread: true }, { id: 4, title: "Τ4", summary: "Σ4", sentAt: SENT, unread: true }], unread: 2 }, { kind: "error" }, { kind: "not_member" }];
    const reads: MessageRead[] = [{ kind: "ok", message: { id: 9, title: "Τ9", summary: "Σ9", sentAt: SENT, html: "<p>x</p>" } }, { kind: "not_found" }, { kind: "error" }];
    let now = 0;
    const api: CustomerMessagesApi = { list: async () => lists.shift()!, get: async () => reads.shift()! };
    const flow = new MemberMessagesFlow(api, () => now);
    await flow.load();
    assert.equal(flow.getState().list.phase, "ready");
    await flow.open(9);
    assert.equal(flow.getState().view.phase, "shown");
    const list = flow.getState().list;
    assert.ok(list.phase === "ready" && list.unread === 0 && list.messages.every((m) => !m.unread));
    await flow.open(7);
    assert.deepEqual(flow.getState().view, { phase: "not_found", id: 7 });
    await flow.open(8);
    assert.deepEqual(flow.getState().view, { phase: "error", id: 8 });
    flow.close();
    assert.deepEqual(flow.getState().view, { phase: "closed" });
    flow.wake();
    assert.equal(lists.length, 2, "wake is throttled");
    now = 60_000;
    flow.wake();
    await Promise.resolve();
    await Promise.resolve();
    assert.equal(flow.getState().list.phase, "ready", "a passing failure keeps the list");
    await flow.load();
    assert.equal(flow.getState().list.phase, "hidden");
    await flow.open(0);
    assert.deepEqual(flow.getState().view, { phase: "not_found", id: 0 });
  });
});

describe("round 18 (D30): the member app", () => {
  const ME: MeResult = { kind: "ok", me: { displayName: "Ελένη Μαρκοπούλου", email: "eleni@example.org", state: "active", paidUntilUtc: "2099-11-15T10:00:00+00:00" } };
  const app = () => dom.document.querySelector('[data-evc-customer="app"]') as HTMLElement;
  const fakeApi = (asked: number[]): CustomerMessagesApi => ({
    list: async () => ({ kind: "ok", messages: [{ id: 9, title: "Νέα γεύση", summary: "Ελάτε να τη δοκιμάσετε.", sentAt: SENT, unread: true }], unread: 1 }),
    get: async (id) => {
      asked.push(id);
      return { kind: "ok", message: { id, title: "Νέα γεύση", summary: "Ελάτε να τη δοκιμάσετε.", sentAt: SENT, html: '<p>Δοκιμάστε <strong>σήμερα</strong>.</p><script>window.__evcPwned = 1</script><img src="https://evil.example/t.png">' } };
    },
  });
  async function mount(flow?: MemberMessagesFlow) {
    const api: CustomerApi = { me: async () => ME };
    await dom.render(createElement(CustomerApp, { config: CONFIG, api, navigate: () => {}, messagesFlow: flow }));
    await act(async () => {});
    await act(async () => {});
  }

  it("«Μηνύματα» lists the member's messages; a tap opens the formatted message; «Πίσω» returns", async () => {
    const asked: number[] = [];
    await mount(new MemberMessagesFlow(fakeApi(asked)));
    const section = app().querySelector('[data-evc-messages="ready"]');
    assert.ok(section, "the list");
    assert.match(section!.textContent ?? "", /Μηνύματα/);
    assert.match(section!.textContent ?? "", /1 νέο/);
    assert.match(section!.textContent ?? "", /Νέα γεύση/);
    assert.match(section!.textContent ?? "", /23 Νοεμβρίου 2026/);
    await dom.click(app().querySelector('[data-evc-message="9"]')!);
    await act(async () => {});
    assert.deepEqual(asked, [9]);
    const view = app().querySelector('[data-evc-message-view="shown"]');
    assert.ok(view, "the message view");
    assert.ok(view!.querySelector('[data-evc-rich="message"] strong'), "formatted");
    assert.ok(view!.querySelector("script") === null);
    assert.ok(view!.querySelector("[data-evc-rich] img") === null, "another origin's image is not drawn");
    assert.equal((dom.window as unknown as Record<string, unknown>).__evcPwned, undefined);
    assert.ok(app().querySelector("[data-evc-messages]") === null, "the list is not under an open message");
    await dom.click(app().querySelector('[data-evc-action="message-back"]')!);
    assert.ok(app().querySelector("[data-evc-message-view]") === null);
    assert.ok(app().querySelector('[data-evc-qr-placeholder], [aria-label="Κάρτα μέλους Evangelou Club"]'), "back on the card");
    assert.match(app().querySelector("[data-evc-messages]")?.textContent ?? "", /Μηνύματα/);
    assert.doesNotMatch(app().querySelector("[data-evc-messages]")?.textContent ?? "", /1 νέο/, "read now");
  });

  it("a notification tap (#message=<id>) opens that message at once and the address loses the hash", async () => {
    const asked: number[] = [];
    dom.window.history.replaceState(null, "", "/club-member/#message=9");
    await mount(new MemberMessagesFlow(fakeApi(asked)));
    assert.deepEqual(asked, [9]);
    assert.ok(app().querySelector('[data-evc-message-view="shown"]'));
    assert.equal(dom.window.location.hash, "", "a reload does not open it again");
    // The open app brought forward by a later tap (same document: hashchange).
    await dom.click(app().querySelector('[data-evc-action="message-back"]')!);
    await act(async () => {
      dom.window.history.replaceState(null, "", "/club-member/#message=12");
      dom.window.dispatchEvent(new dom.window.HashChangeEvent("hashchange"));
    });
    await act(async () => {});
    assert.deepEqual(asked, [9, 12]);
    assert.ok(app().querySelector('[data-evc-message-view="shown"]'));
  });

  it("a bad hash opens nothing; without the flow there is no list and nothing is asked", async () => {
    const asked: number[] = [];
    dom.window.history.replaceState(null, "", "/club-member/#message=javascript:alert(1)");
    await mount(new MemberMessagesFlow(fakeApi(asked)));
    assert.deepEqual(asked, []);
    assert.ok(app().querySelector("[data-evc-message-view]") === null);
    await dom.reset();
    await mount(undefined);
    assert.ok(app().querySelector("[data-evc-messages]") === null);
  });
});
