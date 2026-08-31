import { useState } from "react";

interface StockImageProps {
  src: string;
  alt: string;
  className?: string;
}

/**
 * Renders a locally-hosted stock photo (see scripts/fetch-stock-images.mjs).
 * Falls back to a plain tinted block — never a broken-image icon — if the
 * file is missing or fails to load.
 */
export function StockImage({ src, alt, className = "" }: StockImageProps) {
  const [failed, setFailed] = useState(false);

  if (failed) {
    return <div role="img" aria-label={alt} className={`bg-bronze/12 ${className}`} />;
  }

  return (
    <img
      src={src}
      alt={alt}
      loading="lazy"
      decoding="async"
      onError={() => setFailed(true)}
      className={`object-cover ${className}`}
    />
  );
}
