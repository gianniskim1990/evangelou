/*
 * STAGE-05D: reading the member's QR with the tablet camera, using the
 * browser's own BarcodeDetector (no third-party decoder in the bundle).
 * Where the browser has no BarcodeDetector (e.g. Safari on iPad) the camera
 * button is not shown and the counter uses a hand scanner or the code field.
 * Which device the counter uses is an open question (docs/member-qr.md).
 *
 * Privacy: frames are read in memory only, never recorded, uploaded or kept;
 * the camera is stopped as soon as a code is read, on «Κλείσιμο κάμερας»,
 * when the screen changes and when the session locks.
 */

interface DetectedBarcode {
  rawValue: string;
}

interface BarcodeDetectorLike {
  detect(source: HTMLVideoElement): Promise<DetectedBarcode[]>;
}

export type BarcodeDetectorCtor = new (options: { formats: string[] }) => BarcodeDetectorLike;

export interface CameraEnv {
  BarcodeDetector?: BarcodeDetectorCtor;
  mediaDevices?: Pick<MediaDevices, "getUserMedia">;
  setInterval(fn: () => void, ms: number): unknown;
  clearInterval(id: unknown): void;
}

export type CameraFailure = "denied" | "unavailable";

export const SCAN_INTERVAL_MS = 300;

export function browserCameraEnv(): CameraEnv {
  const w = globalThis as unknown as { BarcodeDetector?: BarcodeDetectorCtor; navigator?: { mediaDevices?: MediaDevices } };
  return {
    BarcodeDetector: w.BarcodeDetector,
    mediaDevices: w.navigator?.mediaDevices,
    setInterval: (fn, ms) => globalThis.setInterval(fn, ms),
    clearInterval: (id) => globalThis.clearInterval(id as ReturnType<typeof setInterval>),
  };
}

export function cameraSupported(env: CameraEnv): boolean {
  return typeof env.BarcodeDetector === "function" && typeof env.mediaDevices?.getUserMedia === "function";
}

export interface CameraSession {
  stop(): void;
}

/**
 * Starts the back camera into `video` and calls `onCode` ONCE with the first
 * QR read, after stopping the camera. Rejects with a CameraFailure.
 */
export async function startQrCamera(video: HTMLVideoElement, onCode: (code: string) => void, env: CameraEnv): Promise<CameraSession> {
  if (!cameraSupported(env)) throw "unavailable" satisfies CameraFailure;
  let stream: MediaStream;
  try {
    stream = await env.mediaDevices!.getUserMedia({ video: { facingMode: "environment" }, audio: false });
  } catch (err) {
    throw ((err as { name?: string } | null)?.name === "NotAllowedError" ? "denied" : "unavailable") satisfies CameraFailure;
  }
  const detector = new env.BarcodeDetector!({ formats: ["qr_code"] });
  let stopped = false;
  let busy = false;
  const stop = () => {
    if (stopped) return;
    stopped = true;
    env.clearInterval(timer);
    for (const track of stream.getTracks()) track.stop();
    video.srcObject = null;
  };
  video.srcObject = stream;
  video.muted = true;
  video.setAttribute("playsinline", "");
  void video.play?.()?.catch?.(() => {});
  const timer = env.setInterval(() => {
    if (stopped || busy) return;
    busy = true;
    detector
      .detect(video)
      .then((codes) => {
        const code = codes.find((c) => typeof c.rawValue === "string" && c.rawValue !== "")?.rawValue;
        if (code !== undefined && !stopped) {
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
