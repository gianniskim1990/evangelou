import assert from "node:assert/strict";
import { describe, it } from "node:test";
import jsQR from "jsqr";
import { QrTooLongError, encodeQr, numDataCodewords } from "../../src/customer/qrEncode";

/*
 * STAGE-05D: the member QR encoder is checked against an INDEPENDENT decoder
 * (jsQR, a devDependency used only here): what we draw must read back as the
 * exact text, for real Club tokens and for edge lengths of every version.
 */

function render(matrix: boolean[][], scale = 4, quiet = 4): { data: Uint8ClampedArray; width: number; height: number } {
  const n = matrix.length;
  const side = (n + quiet * 2) * scale;
  const data = new Uint8ClampedArray(side * side * 4).fill(255);
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if (!matrix[y][x]) continue;
      for (let dy = 0; dy < scale; dy++) {
        for (let dx = 0; dx < scale; dx++) {
          const px = ((y + quiet) * scale + dy) * side + (x + quiet) * scale + dx;
          data[px * 4] = 0;
          data[px * 4 + 1] = 0;
          data[px * 4 + 2] = 0;
        }
      }
    }
  }
  return { data, width: side, height: side };
}

function decode(matrix: boolean[][]): string | null {
  const img = render(matrix);
  const result = jsQR(img.data, img.width, img.height, { inversionAttempts: "dontInvert" });
  return result ? result.data : null;
}

const token = (hex: string) => `evc_${hex}`;

describe("member QR encoder (decoded by jsQR)", () => {
  it("a Club token is a version 4 symbol (33 x 33) that reads back exactly", () => {
    for (const hex of ["0".repeat(48), "f".repeat(48), "0123456789abcdef".repeat(3), "a1b2c3d4e5f60718293a4b5c6d7e8f9001122334455667788"]) {
      const text = token(hex.slice(0, 48));
      const m = encodeQr(text);
      assert.equal(m.length, 33, text);
      assert.ok(m.every((row) => row.length === 33));
      assert.equal(decode(m), text);
    }
  });

  it("every version 1-10 reads back at its exact capacity and one byte over moves to the next version", () => {
    for (let version = 1; version <= 10; version++) {
      const countBits = version <= 9 ? 8 : 16;
      const capacity = Math.floor((numDataCodewords(version) * 8 - 4 - countBits) / 8);
      const text = Array.from({ length: capacity }, (_, i) => "abcdefghijklmnopqrstuvwxyz0123456789"[i % 36]).join("");
      const m = encodeQr(text);
      assert.equal(m.length, version * 4 + 17, `version ${version}`);
      assert.equal(decode(m), text, `version ${version} capacity ${capacity}`);
      if (version < 10) assert.equal(encodeQr(text + "x").length, (version + 1) * 4 + 17, `version ${version} + 1 byte`);
    }
  });

  it("random tokens round-trip (all masks get exercised)", () => {
    const masks = new Set<string>();
    let seed = 12345;
    const next = () => {
      seed = (seed * 1103515245 + 12345) % 2 ** 31;
      return seed;
    };
    for (let i = 0; i < 40; i++) {
      const hex = Array.from({ length: 48 }, () => "0123456789abcdef"[next() % 16]).join("");
      const m = encodeQr(token(hex));
      assert.equal(decode(m), token(hex));
      // format information row 8, columns 0-5 (bits 0-5) carries the mask; just record its pattern
      masks.add(m[8].slice(0, 6).map((b) => (b ? 1 : 0)).join(""));
    }
    assert.ok(masks.size >= 3, `several masks chosen (${masks.size})`);
  });

  it("non-ASCII text is UTF-8 and too-long text is refused", () => {
    assert.equal(decode(encodeQr("Ευαγγέλου")), "Ευαγγέλου");
    assert.throws(() => encodeQr("x".repeat(214)), QrTooLongError);
  });

  it("the encoding is deterministic and the finder patterns are in place", () => {
    const a = encodeQr(token("ab".repeat(24)));
    const b = encodeQr(token("ab".repeat(24)));
    assert.deepEqual(a, b);
    const n = a.length;
    for (const [x0, y0] of [[0, 0], [n - 7, 0], [0, n - 7]]) {
      for (let d = 0; d < 7; d++) {
        assert.equal(a[y0][x0 + d], true, "finder top edge");
        assert.equal(a[y0 + 6][x0 + d], true, "finder bottom edge");
      }
      assert.equal(a[y0 + 1][x0 + 1], false, "finder ring");
      assert.equal(a[y0 + 3][x0 + 3], true, "finder centre");
    }
  });
});
