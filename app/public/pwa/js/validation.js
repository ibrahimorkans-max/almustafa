/**
 * Client-side mirrors of App\Support\Values\PakistanIds (the server re-validates everything).
 */

export function normalizeVehicleNumber(value) {
  return String(value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}

export function isValidVehicleNumber(normalized) {
  // Pakistani marks: 1-4 letters, optional 2-digit year, 1-4 digits (LES-21-1234, MNA-1234, BKX-123).
  return /^[A-Z]{1,4}\d{1,6}$/.test(normalized);
}
