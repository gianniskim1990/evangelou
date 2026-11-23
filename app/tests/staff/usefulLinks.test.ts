import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { parseStaffConfig, parseStaffLinks } from "../../src/staff/config";
import { copyText, LINK_LABELS, UsefulLinks, type ClipboardEnv } from "../../src/staff/UsefulLinks";
import { createDom, type DomHarness } from "./support/dom";

/*
 * STAGE-08 round 18, owner decision D31 (2026-11-23): «Χρήσιμοι σύνδεσμοι» on the till's home screen. The server sends
 * only the addresses of the enabled features (EVC_Club_Links::staff()); the app keeps only known keys whose address
 * is this site's, shows each one with «Αντιγραφή» (clipboard API, then the older copy command), and never an
 * administrator page.
 */

const ORIGIN = "https://shop.example";
const LINKS = [
  { key: "join", url: `${ORIGIN}/prometheus/club/join/` },
  { key: "poster", url: `${ORIGIN}/prometheus/?evc_club_join=poster` },
  { key: "member", url: `${ORIGIN}/prometheus/club-member/` },
  { key: "member_login", url: `${ORIGIN}/prometheus/wp-login.php?redirect_to=https%3A%2F%2Fshop.example%2Fprometheus%2Fclub-member%2F` },
  { key: "till", url: `${ORIGIN}/prometheus/club-admin/` },
];

let dom: DomHarness;
before(async () => {
  dom = await createDom();
});
after(() => dom.dispose());
beforeEach(async () => {
  await dom.reset();
});

describe("round 18 (D31): the till's links from the server config", () => {
  it("keeps the known keys with this site's addresses, in order, each once", () => {
    assert.deepEqual(parseStaffLinks(LINKS, ORIGIN), LINKS);
  });

  it("drops another site, credentials, a fragment, unknown keys (administrator pages included), repeats and junk", () => {
    const got = parseStaffLinks(
      [
        { key: "join", url: "https://evil.example/club/join/" },
        { key: "member", url: "javascript:alert(1)" },
        { key: "till", url: "https://user:pw@shop.example/club-admin/" },
        { key: "poster", url: `${ORIGIN}/club/join/poster/#x` },
        { key: "push", url: `${ORIGIN}/wp-admin/admin.php?page=evc-club-push` },
        { key: "readiness", url: `${ORIGIN}/wp-admin/admin.php?page=evc-club-readiness` },
        { key: "member_login", url: `${ORIGIN}/wp-login.php` },
        { key: "member_login", url: `${ORIGIN}/wp-login.php?again=1` },
        "x",
        null,
      ],
      ORIGIN,
    );
    assert.deepEqual(got, [{ key: "member_login", url: `${ORIGIN}/wp-login.php` }]);
    assert.deepEqual(parseStaffLinks("nope", ORIGIN), []);
    assert.deepEqual(parseStaffLinks(undefined, ORIGIN), []);
  });

  it("a bad links value never refuses the app", () => {
    const base = { restBase: `${ORIGIN}/wp-json/evangelou-club/v1`, nonce: "0a1b2c3d4e", loginUrl: `${ORIGIN}/wp-login.php`, shellUrl: `${ORIGIN}/club-admin/`, idleLockSeconds: 300, sessionRef: "", appVersion: "t", features: {} };
    const good = parseStaffConfig(JSON.stringify({ ...base, links: LINKS }), ORIGIN);
    assert.ok(good.ok);
    assert.deepEqual(good.config.links, LINKS);
    const bad = parseStaffConfig(JSON.stringify({ ...base, links: { join: "x" } }), ORIGIN);
    assert.ok(bad.ok);
    assert.deepEqual(bad.config.links, []);
  });
});

describe("round 18 (D31): «Αντιγραφή»", () => {
  it("the clipboard API in a secure page; the older way when it is refused or missing", async () => {
    const written: string[] = [];
    const fallbacks: string[] = [];
    const env = (clipboard: ClipboardEnv["clipboard"], secure: boolean, fallbackOk = true): ClipboardEnv => ({ clipboard, secure, fallback: (t) => (fallbacks.push(t), fallbackOk) });
    assert.equal(await copyText("a", env({ writeText: async (t) => void written.push(t) }, true)), true);
    assert.deepEqual([written, fallbacks], [["a"], []]);
    assert.equal(await copyText("b", env({ writeText: async () => Promise.reject(new Error("denied")) }, true)), true);
    assert.equal(await copyText("c", env({ writeText: async (t) => void written.push(t) }, false)), true, "not a secure page: the older way");
    assert.equal(await copyText("d", env(null, true)), true);
    assert.deepEqual(fallbacks, ["b", "c", "d"]);
    assert.equal(await copyText("e", env(null, true, false)), false);
  });

  it("the card: every label and address, one copy button each, a polite result line", async () => {
    const copied: string[] = [];
    const clipboard: ClipboardEnv = { clipboard: { writeText: async (t) => void copied.push(t) }, secure: true, fallback: () => false };
    await dom.render(createElement(UsefulLinks, { links: parseStaffLinks(LINKS, ORIGIN), clipboard }));
    const card = dom.document.querySelector('[data-evc-links="staff"]') as HTMLElement;
    assert.ok(card);
    assert.match(card.textContent ?? "", /Χρήσιμοι σύνδεσμοι/);
    for (const link of LINKS) {
      const row = card.querySelector(`[data-evc-link="${link.key}"]`) as HTMLElement;
      assert.ok(row, link.key);
      assert.match(row.textContent ?? "", new RegExp(LINK_LABELS[link.key as keyof typeof LINK_LABELS].replace(/[()]/g, "\\$&")));
      assert.ok((row.textContent ?? "").includes(link.url), link.url);
    }
    const buttons = [...card.querySelectorAll('[data-evc-action="copy-link"]')];
    assert.equal(buttons.length, LINKS.length);
    await dom.click(buttons[2]);
    await new Promise((r) => setTimeout(r, 0));
    await dom.render(createElement(UsefulLinks, { links: parseStaffLinks(LINKS, ORIGIN), clipboard }));
    assert.deepEqual(copied, [LINKS[2].url]);
    assert.match(card.querySelector('[data-evc-link="member"] [role="status"]')?.textContent ?? "", /Αντιγράφηκε/);
    for (const word of ["Ειδοποιήσεις", "Ετοιμότητα", "Βάση δεδομένων", "WooCommerce", "wp-admin"]) {
      assert.ok(!(card.textContent ?? "").includes(word), `no administrator page: ${word}`);
    }
  });

  it("no links: no card", async () => {
    await dom.render(createElement(UsefulLinks, { links: [] }));
    assert.ok(dom.document.querySelector("[data-evc-links]") === null);
  });
});
