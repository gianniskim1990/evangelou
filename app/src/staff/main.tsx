import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { CONFIG_ELEMENT_ID, parseStaffConfig } from "./config";
import { IdleLock, installIdleLock } from "./idleLock";
import { createStaffApi } from "./staffApi";
import { ConfigErrorScreen, StaffApp } from "./StaffApp";
import { StaffController } from "./staffController";
import { CounterFlow } from "./member/counterFlow";
import { createMemberApi } from "./member/memberApi";
import { browserCameraEnv } from "./member/qrCamera";

// REAL staff entrypoint, served only by the WordPress plugin at /club-admin/.
// It never imports the ordering app, the ordering admin or any demo/mock module.

document.documentElement.classList.toggle("dark", window.matchMedia?.("(prefers-color-scheme: dark)").matches === true);

const configElement = document.getElementById(CONFIG_ELEMENT_ID);
const parsed = parseStaffConfig(configElement?.textContent ?? null, window.location.origin);
// The nonce now lives only in memory; drop it from the DOM.
configElement?.remove();

const root = createRoot(document.getElementById("root")!);

function safeSessionStorage(): Storage | null {
  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
}

if (!parsed.ok) {
  root.render(
    <StrictMode>
      <ConfigErrorScreen />
    </StrictMode>,
  );
} else {
  const config = parsed.config;
  const staffApi = createStaffApi(config);
  const controller = new StaffController({
    api: staffApi,
    config,
    navigate: (url) => window.location.assign(url),
    storage: safeSessionStorage(),
  });

  const idle = new IdleLock({
    timeoutMs: config.idleLockSeconds * 1000,
    clock: { now: () => Date.now() },
    onLock: () => void controller.lock(),
  });
  installIdleLock(idle, {
    addEventListener: (type, listener, options) =>
      (type === "visibilitychange" ? document : window).addEventListener(type, listener, options),
    removeEventListener: (type, listener, options) =>
      (type === "visibilitychange" ? document : window).removeEventListener(type, listener, options),
    setInterval: (fn, ms) => window.setInterval(fn, ms),
    clearInterval: (id) => window.clearInterval(id as number),
    isVisible: () => document.visibilityState === "visible",
  });

  // Returning to the tab: first the elapsed-time lock check, then a status
  // check (which never extends the server inactivity window).
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && !idle.check()) void controller.refreshStatus();
  });

  // STAGE-05D: the counter (QR scan + free coffee). Constructing it sends nothing; StaffApp shows it only
  // while GET /session reports features.qr_lookup === true.
  const counter = new CounterFlow({ controller, api: createMemberApi(staffApi, config.restBase) });

  root.render(
    <StrictMode>
      <StaffApp controller={controller} counter={counter} camera={browserCameraEnv()} />
    </StrictMode>,
  );
  void controller.boot();
}
