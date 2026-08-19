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
 * The customer's installation-date day is SolarNet's monthly billing cycle.
 * Work from the date string instead of Date parsing to avoid timezone shifts.
 */
export const monthlyDueDateLabel = (installationDate: string): string => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(installationDate);
  const day = Number(match?.[3]);
  return day >= 1 && day <= 31
    ? `Due date: every ${ordinal(day)} of the month`
    : 'Select an installation date to set the monthly due date.';
};
