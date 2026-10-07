/**
 * Client-side mirrors of App\Support\Values\IndianIds (the server re-validates everything).
 */

export function normalizeVehicleNumber(value) {
  return String(value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}

export function isValidVehicleNumber(normalized) {
  return /^[A-Z]{2}\d{1,2}[A-Z]{0,3}\d{1,4}$/.test(normalized) || /^\d{2}BH\d{4}[A-Z]{1,2}$/.test(normalized);
}
