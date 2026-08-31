// Greek → Latin transliteration used to derive stable, ASCII filenames
// (e.g. for stock images) from Greek product/category names.
const GREEK_MAP: Record<string, string> = {
  ά: "a", έ: "e", ή: "i", ί: "i", ό: "o", ύ: "y", ώ: "o",
  ϊ: "i", ΐ: "i", ϋ: "y", ΰ: "y",
  α: "a", β: "v", γ: "g", δ: "d", ε: "e", ζ: "z", η: "i", θ: "th",
  ι: "i", κ: "k", λ: "l", μ: "m", ν: "n", ξ: "x", ο: "o", π: "p",
  ρ: "r", σ: "s", ς: "s", τ: "t", υ: "y", φ: "f", χ: "ch", ψ: "ps", ω: "o",
};

export function slugify(text: string): string {
  const lower = text.toLowerCase();
  let out = "";
  for (const ch of lower) out += GREEK_MAP[ch] ?? ch;
  return out
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .replace(/-{2,}/g, "-");
}
