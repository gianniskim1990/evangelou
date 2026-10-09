import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { athensBusinessDate, formatClockTime, formatGreekLongDate, todayKey } from "../../src/club/format";

describe("Athens business date (device timezone independent; tests run with TZ=UTC+14)", () => {
  const cases: [string, string][] = [
    ["2026-01-01T21:59:59.999Z", "2026-01-01"],
    ["2026-01-01T22:00:00Z", "2026-01-02"],
    ["2026-07-01T20:59:59Z", "2026-07-01"],
    ["2026-07-01T21:00:00Z", "2026-07-02"],
    ["2026-03-28T22:00:00Z", "2026-03-29"],
    ["2026-03-29T20:59:59Z", "2026-03-29"],
    ["2026-03-29T21:00:00Z", "2026-03-30"],
    ["2026-10-24T21:00:00Z", "2026-10-25"],
    ["2026-10-25T21:59:59Z", "2026-10-25"],
    ["2026-10-25T22:00:00Z", "2026-10-26"],
  ];
  for (const [instant, expected] of cases) {
    it(`${instant} -> ${expected}`, () => {
      assert.equal(athensBusinessDate(new Date(instant)), expected);
      assert.equal(todayKey(new Date(instant)), expected);
    });
  }
});

describe("display formatting", () => {
  it("formats plain dates and offset instants as the Athens calendar day", () => {
    assert.equal(formatGreekLongDate("2026-10-15"), formatGreekLongDate("2026-10-15T23:59:59+03:00"));
    assert.match(formatGreekLongDate("2026-10-15"), /15/);
    assert.match(formatGreekLongDate("2026-10-15"), /2026/);
    // 21:30Z on 15 Oct is 00:30 on 16 Oct in Athens.
    assert.match(formatGreekLongDate("2026-10-15T21:30:00Z"), /16/);
  });

  it("shows clock times in Athens time", () => {
    assert.match(formatClockTime("2026-10-09T07:42:00Z"), /10:42/);
    assert.match(formatClockTime("2026-01-09T08:42:00Z"), /10:42/);
  });
});
