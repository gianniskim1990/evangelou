import { useRef, useState } from "react";
import { compressImageToDataUrl } from "../lib/imageUpload";

interface Props {
  label: string;
  disabledReason?: string;
  onUpload: (dataUrl: string) => Promise<void>;
}

/** File-picker button that compresses the chosen image in the browser
 * (src/lib/imageUpload.ts) then hands the caller a data URL to upload. */
export function ImageUploadButton({ label, disabledReason, onUpload }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  const handleChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setBusy(true);
    setError("");
    try {
      const dataUrl = await compressImageToDataUrl(file);
      await onUpload(dataUrl);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Αποτυχία μεταφόρτωσης.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex items-center gap-2">
      <button
        type="button"
        disabled={!!disabledReason || busy}
        title={disabledReason}
        onClick={() => inputRef.current?.click()}
        className="rounded-lg border border-espresso/20 px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40"
      >
        {busy ? "Μεταφόρτωση…" : label}
      </button>
      <input ref={inputRef} type="file" accept="image/*" className="hidden" onChange={handleChange} />
      {error && <span className="text-xs text-maroon">{error}</span>}
    </span>
  );
}
