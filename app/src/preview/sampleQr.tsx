import { cx } from "./screens";

/*
 * STAGE-08 round 17 (owner-found bug, staging 2026-11-23): the design preview's SAMPLE QR lives ONLY here.
 * It is a QR-looking decoration, never an encoded code, stamped «ΔΕΙΓΜΑ / ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ». The real member
 * app (src/customer/main.tsx) must never reach this module: tests/club/customerSampleGuard.test.ts checks its
 * import graph and the packager (scripts/sample-guard.mjs) refuses a customer bundle that contains the sample
 * pattern or its stamp. Only the preview wrappers (customerSampleScreens.tsx) import it.
 */

/** Deterministic QR-LOOKING module pattern (not an encoded QR code). */
export function sampleQrModules(seed: number, n = 25): [number, number][] {
  let s = seed * 9301 + 49297;
  const out: [number, number][] = [];
  const inFinder = (r: number, c: number) => {
    const box = (r0: number, c0: number) => r >= r0 && r < r0 + 7 && c >= c0 && c < c0 + 7;
    return box(0, 0) || box(0, n - 7) || box(n - 7, 0);
  };
  for (let r = 0; r < n; r++) {
    for (let c = 0; c < n; c++) {
      if (inFinder(r, c)) continue;
      s = (s * 9301 + 49297) % 233280;
      if (s / 233280 > 0.52) out.push([r, c]);
    }
  }
  return out;
}

/**
 * A QR-LOOKING pattern for the membership pass. NOT an encoded QR code
 * (seeded random modules), so it cannot be scanned or redeemed, and it is
 * permanently stamped "ΔΕΙΓΜΑ / ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ".
 */
export function PassQr({ seed = 11, size = 196, muted = false }: { seed?: number; size?: number; muted?: boolean }) {
  const n = 25;
  const finder = (x: number, y: number) => (
    <g key={`f${x}-${y}`}>
      <rect x={x} y={y} width="7" height="7" rx="1.4" />
      <rect x={x + 1} y={y + 1} width="5" height="5" rx="0.9" fill="#fffdf9" />
      <rect x={x + 2} y={y + 2} width="3" height="3" rx="0.6" />
    </g>
  );
  return (
    <div className="relative inline-block" data-evc-preview="sample-qr">
      <svg width={size} height={size} viewBox={`-1 -1 ${n + 2} ${n + 2}`} role="img" aria-label="Δείγμα κωδικού QR, μη εξαργυρώσιμο" className={cx("block", muted && "opacity-30")}>
        <g fill="#2a211b">
          {sampleQrModules(seed, n).map(([r, c]) => (
            <rect key={`${r}-${c}`} x={c + 0.06} y={r + 0.06} width="0.88" height="0.88" rx="0.22" />
          ))}
          {finder(0, 0)}
          {finder(n - 7, 0)}
          {finder(0, n - 7)}
        </g>
      </svg>
      <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
        <span className="-rotate-[8deg] border-[1.5px] border-[var(--stop)] bg-[#fffdf9] px-2.5 py-1 text-center text-[11px] leading-[1.25] font-bold tracking-[0.06em] text-[var(--stop)]">
          ΔΕΙΓΜΑ
          <br />
          ΜΗ ΕΞΑΡΓΥΡΩΣΙΜΟ
        </span>
      </div>
    </div>
  );
}
