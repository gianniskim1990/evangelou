export function fmt(n: number): string {
  return n.toFixed(2).replace(".", ",") + " €";
}

/** Parses a "09:00–23:00" style range into [startHour, endHour]. */
export function parseHoursRange(hours: string): [number, number] {
  const [start, end] = hours.split(/[–-]/);
  const startHour = parseInt(start, 10) || 9;
  const endHour = parseInt(end, 10) || 23;
  return [startHour, endHour];
}

/** 15-minute pickup slots between the store's opening hours. */
export function pickupSlots(hours: string): string[] {
  const [startHour, endHour] = parseHoursRange(hours);
  const slots: string[] = [];
  let h = startHour;
  let m = 0;
  while (h < endHour) {
    slots.push(`${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`);
    m += 15;
    if (m >= 60) {
      m = 0;
      h++;
    }
  }
  return slots;
}

export function isStoreClosedNow(hours: string): boolean {
  const [startHour, endHour] = parseHoursRange(hours);
  const nowH = new Date().getHours();
  return nowH < startHour || nowH >= endHour;
}
