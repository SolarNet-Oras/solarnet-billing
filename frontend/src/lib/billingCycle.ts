const ordinal = (day: number): string => {
  const remainder = day % 100;
  if (remainder >= 11 && remainder <= 13) return `${day}th`;
  switch (day % 10) {
    case 1: return `${day}st`;
    case 2: return `${day}nd`;
    case 3: return `${day}rd`;
    default: return `${day}th`;
  }
};

/**
 * The customer's explicitly configured billing_cycle_day is SolarNet's
 * monthly billing cycle.
 */
export const monthlyDueDateLabel = (billingCycleDay: number | string | null | undefined): string => {
  const day = Number(billingCycleDay);
  return day >= 1 && day <= 31
    ? `Due date: every ${ordinal(day)} of the month`
    : 'Set a monthly due day for this client.';
};
