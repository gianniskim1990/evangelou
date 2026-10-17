import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../../index.css";
import { Preview } from "./Preview";

/**
 * UI-04N LOCAL-ONLY visual preview of the Staff Recovery Screen.
 *
 * Opened with `npm run recovery:preview` (Vite dev server). It is not an input
 * of any production build, is refused outside the dev server, uses only the
 * fake states of previewScenarios.ts and never makes a request.
 */
const root = document.getElementById("root");
if (!import.meta.env.DEV) {
  if (root) root.textContent = "The recovery preview is available only on the local development server.";
} else if (root) {
  createRoot(root).render(
    <StrictMode>
      <Preview />
    </StrictMode>,
  );
}
