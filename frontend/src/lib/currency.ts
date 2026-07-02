/**
 * Format a number as Philippine Peso currency.
 * Uses PH locale grouping (1,234.56) and the ₱ sign.
 *
 * formatPHP(1234.5)  // "₱1,234.50"
 * formatPHP(null)    // "₱0.00"
 */
export const formatPHP = (value: unknown): string => {
  const n = Number(value);
  const safe = Number.isFinite(n) ? n : 0;
  return `₱${safe.toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
};

/** Just the number portion, still PH-locale grouped (no ₱). */
export const formatPHPNumber = (value: unknown): string => {
  const n = Number(value);
  const safe = Number.isFinite(n) ? n : 0;
  return safe.toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

export const PESO_SIGN = '₱' as const;
