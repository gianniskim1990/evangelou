// Downscales and compresses an image File entirely in the browser (canvas)
// before it's sent to the admin upload API — keeps what we store in Redis
// small, and needs no server-side image library (no native-binary
// dependency to worry about inside a Vercel Function).
const MAX_DIMENSION = 800;
const JPEG_QUALITY = 0.75;

export async function compressImageToDataUrl(file: File): Promise<string> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
  const width = Math.round(bitmap.width * scale);
  const height = Math.round(bitmap.height * scale);

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("Δεν ήταν δυνατή η επεξεργασία της εικόνας.");
  ctx.drawImage(bitmap, 0, 0, width, height);

  return canvas.toDataURL("image/jpeg", JPEG_QUALITY);
}
