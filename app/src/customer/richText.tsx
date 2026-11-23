import type { CSSProperties, ReactNode } from "react";

/*
 * STAGE-08 round 18, owner decision D30 (2026-11-23): draws the formatted body of an in-app message.
 *
 * The server already sanitised the body (EVC_Club_Messages::sanitize_html(): wp_kses allowlist + a DOM pass). This
 * file does NOT trust that: it never puts HTML into the page (no innerHTML, no dangerouslySetInnerHTML). The string is
 * parsed by the browser's DOMParser into an inert document (no script runs, no image loads, no style applies there),
 * and only an allowlist is turned into React elements; everything else is dropped (an unknown element keeps its text
 * only; script, style, frames, forms and the like go with their content). React then builds real elements and sets the few allowed styles through the CSSOM, which the member page's
 * strict CSP allows (style-src 'self' forbids style attributes in markup, not element.style).
 *
 *   elements    p, br, strong, b, em, i, u, s, del, span, h2, h3, h4, ul, ol, li, blockquote, hr, a, img
 *   a           href only http(s) (a real URL), mailto: or tel:; opens in a new tab, rel="noopener noreferrer nofollow"
 *   img         src only on THIS origin (the media library; the CSP img-src 'self' says the same), alt, width, height
 *   style       color (#hex / rgb()), text-align, text-decoration (underline / line-through), font-size 8–36 px / pt
 *   no other attribute of any element (no class, id, on*, target from the message, srcset, …)
 * Depth and size are bounded (MAX_DEPTH, MAX_NODES): a hostile body cannot exhaust the page.
 */

export const RICH_TAGS = ["p", "br", "strong", "b", "em", "i", "u", "s", "del", "span", "h2", "h3", "h4", "ul", "ol", "li", "blockquote", "hr", "a", "img"] as const;
const STYLED = new Set(["p", "span", "h2", "h3", "h4", "li"]);
const VOID = new Set(["br", "hr", "img"]);
/** Elements whose content is never text for the reader: dropped with everything inside them. */
const DROPPED = new Set(["script", "style", "iframe", "object", "embed", "noscript", "template", "textarea", "select", "svg", "math", "title", "head", "form"]);
const MAX_DEPTH = 24;
const MAX_NODES = 4000;
const UNSAFE = /[\u0000- "'<>\\`]/;

/** Only the allowed declarations, as a React style object (null = none). */
export function richStyle(style: string | null): CSSProperties | null {
  if (!style) return null;
  const out: CSSProperties = {};
  let any = false;
  for (const decl of style.split(";")) {
    const at = decl.indexOf(":");
    if (at < 0) continue;
    const prop = decl.slice(0, at).trim().toLowerCase();
    const value = decl.slice(at + 1).trim().toLowerCase();
    if (prop === "color" && (/^#[0-9a-f]{3}([0-9a-f]{3})?$/.test(value) || /^rgb\(\s*(25[0-5]|2[0-4]\d|1?\d{1,2})\s*,\s*(25[0-5]|2[0-4]\d|1?\d{1,2})\s*,\s*(25[0-5]|2[0-4]\d|1?\d{1,2})\s*\)$/.test(value))) {
      out.color = value;
      any = true;
    } else if (prop === "text-align" && ["left", "right", "center", "justify"].includes(value)) {
      out.textAlign = value as CSSProperties["textAlign"];
      any = true;
    } else if (prop === "text-decoration" && (value === "underline" || value === "line-through")) {
      out.textDecoration = value;
      any = true;
    } else if (prop === "font-size") {
      const m = /^(\d{1,2})(px|pt)$/.exec(value);
      if (m && Number(m[1]) >= 8 && Number(m[1]) <= 36) {
        out.fontSize = value;
        any = true;
      }
    }
  }
  return any ? out : null;
}

/** A link a message may carry: http(s) with a host, mailto: or tel:. */
export function richHref(href: string | null): string | null {
  if (!href) return null;
  const h = href.trim();
  if (h === "" || UNSAFE.test(h)) return null;
  if (/^(mailto|tel):\S+$/i.test(h)) return h;
  try {
    const url = new URL(h);
    return (url.protocol === "https:" || url.protocol === "http:") && url.hostname !== "" ? url.href : null;
  } catch {
    return null;
  }
}

/** An image a message may show: this origin only (absolute or relative to it). */
export function richImage(src: string | null, origin: string): string | null {
  if (!src) return null;
  const s = src.trim();
  if (s === "" || UNSAFE.test(s) || s.includes("..")) return null;
  try {
    const url = new URL(s, origin);
    return url.origin === origin && (url.protocol === "https:" || url.protocol === "http:") ? url.href : null;
  } catch {
    return null;
  }
}

interface ParserLike {
  parseFromString(text: string, type: "text/html"): { body: Node | null };
}

/** The allowlisted React tree of a sanitised body ([] for anything empty or unparsable). */
export function renderRichText(html: string, origin: string, parser: ParserLike | null = typeof DOMParser === "undefined" ? null : new DOMParser()): ReactNode[] {
  if (typeof html !== "string" || html.trim() === "" || parser === null) return [];
  let doc: { body: Node | null };
  try {
    doc = parser.parseFromString(html, "text/html");
  } catch {
    return [];
  }
  if (!doc.body) return [];
  const budget = { left: MAX_NODES };
  return children(doc.body, origin, 0, budget);
}

function children(node: Node, origin: string, depth: number, budget: { left: number }): ReactNode[] {
  const out: ReactNode[] = [];
  node.childNodes.forEach((child, i) => {
    if (budget.left <= 0) return;
    budget.left--;
    const drawn = element(child, origin, depth, budget, i);
    if (drawn !== null) out.push(drawn);
  });
  return out;
}

function element(node: Node, origin: string, depth: number, budget: { left: number }, key: number): ReactNode {
  // 3 = text, 1 = element; comments, processing instructions and the rest are dropped.
  if (node.nodeType === 3) return node.nodeValue ?? "";
  if (node.nodeType !== 1) return null;
  const el = node as Element;
  const tag = el.tagName.toLowerCase();
  if (DROPPED.has(tag)) return null;
  if (!(RICH_TAGS as readonly string[]).includes(tag) || depth >= MAX_DEPTH) return el.textContent ?? "";
  if (tag === "img") {
    const src = richImage(el.getAttribute("src"), origin);
    if (src === null) return null;
    const dims: { width?: number; height?: number } = {};
    for (const d of ["width", "height"] as const) {
      const v = el.getAttribute(d);
      if (v !== null && /^[1-9]\d{0,3}$/.test(v)) dims[d] = Number(v);
    }
    return <img key={key} src={src} alt={el.getAttribute("alt") ?? ""} {...dims} loading="lazy" decoding="async" referrerPolicy="no-referrer" style={{ maxWidth: "100%", height: "auto" }} />;
  }
  if (VOID.has(tag)) return tag === "br" ? <br key={key} /> : <hr key={key} />;
  const kids = children(el, origin, depth + 1, budget);
  const style = STYLED.has(tag) ? richStyle(el.getAttribute("style")) : null;
  if (tag === "a") {
    const href = richHref(el.getAttribute("href"));
    if (href === null) return <span key={key}>{kids}</span>;
    return (
      <a key={key} href={href} target="_blank" rel="noopener noreferrer nofollow">
        {kids}
      </a>
    );
  }
  const Tag = tag as "p";
  return style ? (
    <Tag key={key} style={style}>
      {kids}
    </Tag>
  ) : (
    <Tag key={key}>{kids}</Tag>
  );
}

/** The body inside its own container (typography only; nothing from the message reaches the page as HTML). */
export function RichText({ html, origin }: { html: string; origin: string }) {
  const nodes = renderRichText(html, origin);
  if (nodes.length === 0) return null;
  return (
    <div className="evc-rich t-body text-[var(--ink)]" data-evc-rich="message">
      {nodes}
    </div>
  );
}
