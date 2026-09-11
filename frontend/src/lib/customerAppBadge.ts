type BadgeNavigator = Navigator & {
  setAppBadge?: (contents?: number) => Promise<void>;
  clearAppBadge?: () => Promise<void>;
};

export const showSuspensionBadge = (): void => {
  const badgeNavigator = navigator as BadgeNavigator;
  if (typeof badgeNavigator.setAppBadge !== 'function') return;
  void badgeNavigator.setAppBadge(1).catch(() => undefined);
};

export const clearSuspensionBadge = (): void => {
  const badgeNavigator = navigator as BadgeNavigator;
  if (typeof badgeNavigator.clearAppBadge !== 'function') return;
  void badgeNavigator.clearAppBadge().catch(() => undefined);
};
