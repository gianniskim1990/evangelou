/**
 * Idempotency keys for redemptions: random RFC 4122 version-4 UUIDs, lower
 * case (the backend requires exactly this format). Never derived from member
 * ids, coffee codes, time or anything else guessable.
 */
export const REQUEST_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

type CryptoLike = Pick<Crypto, "getRandomValues"> & { randomUUID?: () => string };

export function newRequestId(c: CryptoLike = globalThis.crypto): string {
  if (typeof c.randomUUID === "function") {
    return c.randomUUID().toLowerCase();
  }
  // randomUUID exists only in secure contexts (https/localhost); a tablet on
  // plain http still has getRandomValues.
  const bytes = c.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function isValidRequestId(value: unknown): value is string {
  return typeof value === "string" && REQUEST_ID_PATTERN.test(value);
}
