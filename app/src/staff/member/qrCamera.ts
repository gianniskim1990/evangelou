/*
 * STAGE-05D: reading the member's QR with the tablet camera.
 *
 * Owner decision 2026-11-04 («Δεν ξέρω ακόμα, να δουλεύει σε όλες»): the counter must work on
 * an iPad, an Android tablet and with a USB hand scanner. So:
 *   - the browser's own BarcodeDetector when it has one (Chrome on Android);
 *   - otherwise (Safari on iPad, Firefox) jsQR, loaded LAZILY (its own chunk,
 *     fetched from the same plugin folder only when the camera is opened) and fed
 *     downscaled frames (longest side <= DECODE_MAX_SIDE) from an in-memory canvas;
 *   - the hand scanner always works through the code field (CounterScreens).
 * Decoding runs on the main thread every SCAN_INTERVAL_MS on a small frame: a
 * Worker would need a separately emitted worker file (outside the packaged
 * manifest checks) and a worker-src rule in the /club-admin/ CSP, for no
 * visible gain at this frame size (docs/member-qr.md).
 *
 * Privacy: frames stay in memory only; nothing is recorded, uploaded or kept.
 * The camera stops as soon as a code is read, on «Κλείσιμο κάμερας», when the
 * screen changes and when the session locks, INCLUDING when that happens while
 * the browser is still asking for the camera (the AbortSignal: a stream that
 * arrives after the abort is stopped at once).
 */

interface DetectedBarcode {
  rawValue: string;
}

interface BarcodeDetectorLike {
  detect(source: HTMLVideoElement): Promise<DetectedBarcode[]>;
}

export type BarcodeDetectorCtor = new (options: { formats: string[] }) => BarcodeDetectorLike;

/** RGBA pixels -> the QR text, or null. */
export type FrameDecoder = (data: Uint8ClampedArray, width: number, height: number) => string | null;

export interface FrameCanvas {
  width: number;
  height: number;
  getContext(kind: "2d", options?: { willReadFrequently?: boolean }): {
    drawImage(source: HTMLVideoElement, dx: number, dy: number, dw: number, dh: number): void;
    getImageData(sx: number, sy: number, sw: number, sh: number): { data: Uint8ClampedArray; width: number; height: number };
  } | null;
}

export interface CameraEnv {
  BarcodeDetector?: BarcodeDetectorCtor;
  mediaDevices?: Pick<MediaDevices, "getUserMedia">;
  /** Lazy fallback decoder (jsQR), used only without BarcodeDetector. */
  loadDecoder?: () => Promise<FrameDecoder>;
  createCanvas?: () => FrameCanvas;
  setInterval(fn: () => void, ms: number): unknown;
  clearInterval(id: unknown): void;
}

export type CameraFailure = "denied" | "unavailable" | "aborted";

export const SCAN_INTERVAL_MS = 300;
export const DECODE_MAX_SIDE = 480;

export function browserCameraEnv(): CameraEnv {
  const w = globalThis as unknown as { BarcodeDetector?: BarcodeDetectorCtor; navigator?: { mediaDevices?: MediaDevices }; document?: Document };
  return {
    BarcodeDetector: typeof w.BarcodeDetector === "function" ? w.BarcodeDetector : undefined,
    mediaDevices: w.navigator?.mediaDevices,
    loadDecoder: async () => {
      const { default: jsQR } = await import("jsqr");
      return (data, width, height) => jsQR(data, width, height, { inversionAttempts: "dontInvert" })?.data ?? null;
    },
    createCanvas: w.document ? () => w.document!.createElement("canvas") as unknown as FrameCanvas : undefined,
    setInterval: (fn, ms) => globalThis.setInterval(fn, ms),
    clearInterval: (id) => globalThis.clearInterval(id as ReturnType<typeof setInterval>),
  };
}

/** A camera can be offered: getUserMedia plus BarcodeDetector OR the lazy decoder with a canvas. */
export function cameraSupported(env: CameraEnv): boolean {
  if (typeof env.mediaDevices?.getUserMedia !== "function") return false;
  return typeof env.BarcodeDetector === "function" || (typeof env.loadDecoder === "function" && typeof env.createCanvas === "function");
}

export interface CameraSession {
  stop(): void;
}

type Reader = () => Promise<string | null>;

function nativeReader(env: CameraEnv, video: HTMLVideoElement): Reader {
  const detector = new env.BarcodeDetector!({ formats: ["qr_code"] });
  return async () => (await detector.detect(video)).find((c) => typeof c.rawValue === "string" && c.rawValue !== "")?.rawValue ?? null;
}

function frameReader(decode: FrameDecoder, canvas: FrameCanvas, video: HTMLVideoElement, onStop: (fn: () => void) => void): Reader {
  // The last frame never outlives the camera: an empty canvas on stop().
  onStop(() => {
    canvas.width = 0;
    canvas.height = 0;
  });
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  return async () => {
    const w = video.videoWidth;
    const h = video.videoHeight;
    if (!ctx || !w || !h) return null;
    const scale = Math.min(1, DECODE_MAX_SIDE / Math.max(w, h));
    canvas.width = Math.max(1, Math.round(w * scale));
    canvas.height = Math.max(1, Math.round(h * scale));
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
    return decode(frame.data, frame.width, frame.height);
  };
}

/**
 * Starts the back camera into `video` and calls `onCode` ONCE with the first
 * QR read, after stopping the camera. Rejects with a CameraFailure; "aborted"
 * when `signal` fired first (the stream, if it came, is already stopped).
 */
export async function startQrCamera(video: HTMLVideoElement, onCode: (code: string) => void, env: CameraEnv, signal: AbortSignal): Promise<CameraSession> {
  if (signal.aborted) throw "aborted" satisfies CameraFailure;
  if (!cameraSupported(env)) throw "unavailable" satisfies CameraFailure;
  let stream: MediaStream;
  try {
    stream = await env.mediaDevices!.getUserMedia({ video: { facingMode: "environment" }, audio: false });
  } catch (err) {
    // Refused after the screen was already closed / locked: nothing to report any more.
    if (signal.aborted) throw "aborted" satisfies CameraFailure;
    throw ((err as { name?: string } | null)?.name === "NotAllowedError" ? "denied" : "unavailable") satisfies CameraFailure;
  }
  const release = () => {
    for (const track of stream.getTracks()) track.stop();
  };
  let read: Reader;
  const onStops: (() => void)[] = [];
  try {
    if (typeof env.BarcodeDetector === "function") read = nativeReader(env, video);
    else {
      const decode = await env.loadDecoder!();
      read = frameReader(decode, env.createCanvas!(), video, (fn) => onStops.push(fn));
    }
  } catch {
    release();
    // Round 4: a decoder that fails to load after Close / lock is not an error to show.
    throw (signal.aborted ? "aborted" : "unavailable") satisfies CameraFailure;
  }
  // Closed, locked or left while the browser was still asking (or the decoder was loading): never keep
  // the camera on. ONE check after every await, so no late path can skip it.
  if (signal.aborted) {
    release();
    throw "aborted" satisfies CameraFailure;
  }

  let stopped = false;
  let busy = false;
  let timer: unknown = null;
  const stop = () => {
    if (stopped) return;
    stopped = true;
    signal.removeEventListener("abort", stop);
    if (timer !== null) env.clearInterval(timer);
    release();
    video.srcObject = null;
    for (const fn of onStops) fn();
  };
  signal.addEventListener("abort", stop, { once: true });
  video.srcObject = stream;
  video.muted = true;
  video.setAttribute("playsinline", "");
  void video.play?.()?.catch?.(() => {});
  timer = env.setInterval(() => {
    if (stopped || busy) return;
    busy = true;
    read()
      .then((code) => {
        if (code !== null && code !== "" && !stopped) {
          stop();
          onCode(code);
        }
      })
      .catch(() => {})
      .finally(() => {
        busy = false;
      });
  }, SCAN_INTERVAL_MS);
  return { stop };
}
