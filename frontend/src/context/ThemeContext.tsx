import React, { createContext, useCallback, useEffect, useMemo, useState } from 'react';

// ============================================================================
// Types
// ============================================================================

export type Theme = 'light' | 'dark' | 'system';
export type ResolvedTheme = Exclude<Theme, 'system'>;

interface ThemeContextType {
  theme: Theme;
  resolvedTheme: ResolvedTheme;
  toggleTheme: () => void;
  setTheme: (theme: Theme) => void;
}

const THEME_STORAGE_KEY = 'solarnet-theme';
const LEGACY_THEME_STORAGE_KEY = 'theme';

const getSystemTheme = (): ResolvedTheme =>
  window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';

const getStoredTheme = (): Theme => {
  try {
    const stored = localStorage.getItem(THEME_STORAGE_KEY) || localStorage.getItem(LEGACY_THEME_STORAGE_KEY);
    return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
  } catch {
    return 'system';
  }
};

// ============================================================================
// Context
// ============================================================================

export const ThemeContext = createContext<ThemeContextType | undefined>(undefined);

// ============================================================================
// Provider
// ============================================================================

interface ThemeProviderProps {
  children: React.ReactNode;
}

export const ThemeProvider: React.FC<ThemeProviderProps> = ({ children }) => {
  const [theme, setThemeState] = useState<Theme>(getStoredTheme);
  const [resolvedTheme, setResolvedTheme] = useState<ResolvedTheme>(() => {
    const preference = getStoredTheme();
    return preference === 'system' ? getSystemTheme() : preference;
  });

  useEffect(() => {
    const root = window.document.documentElement;
    const applyTheme = (): void => {
      const nextResolved = theme === 'system' ? getSystemTheme() : theme;
      setResolvedTheme(nextResolved);
      root.classList.remove('light', 'dark');
      root.classList.add(nextResolved);
      root.style.colorScheme = nextResolved;
      root.dataset.theme = theme;
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', nextResolved === 'dark' ? '#020817' : '#eef2f7');
    };

    applyTheme();

    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const syncSystemTheme = (): void => {
      if (theme === 'system') applyTheme();
    };
    media.addEventListener('change', syncSystemTheme);

    try {
      localStorage.setItem(THEME_STORAGE_KEY, theme);
      // Keep the existing key in sync so older app shells do not flash a conflicting theme.
      localStorage.setItem(LEGACY_THEME_STORAGE_KEY, theme);
    } catch (error) {
      console.warn('Failed to save theme preference:', error);
    }

    return () => media.removeEventListener('change', syncSystemTheme);
  }, [theme]);

  const setTheme = useCallback((newTheme: Theme): void => setThemeState(newTheme), []);

  const toggleTheme = useCallback((): void => {
    setThemeState((previous) => previous === 'light' ? 'dark' : previous === 'dark' ? 'system' : 'light');
  }, []);

  const value = useMemo<ThemeContextType>(() => ({
    theme,
    resolvedTheme,
    toggleTheme,
    setTheme,
  }), [theme, resolvedTheme, setTheme, toggleTheme]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
};
