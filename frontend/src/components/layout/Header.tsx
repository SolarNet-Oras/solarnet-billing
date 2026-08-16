import React, { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';

interface HeaderProps {
  onMenuClick: () => void;
  title?: string;
  subtitle?: string;
}

export const Header: React.FC<HeaderProps> = ({ onMenuClick, title = 'Network Operations Center', subtitle }) => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const userMenu = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const closeMenu = (event: MouseEvent) => {
      if (userMenu.current && !userMenu.current.contains(event.target as Node)) setMenuOpen(false);
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenuOpen(false);
    };
    document.addEventListener('mousedown', closeMenu);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('mousedown', closeMenu);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, []);

  const handleLogout = async (): Promise<void> => {
    setMenuOpen(false);
    await logout();
    navigate('/login');
  };

  const goTo = (path: string): void => {
    setMenuOpen(false);
    navigate(path);
  };

  const primaryRole = user?.roles?.[0];
  const roleLabel = typeof primaryRole === 'string' ? primaryRole : primaryRole?.display_name || primaryRole?.name || 'User';

  return (
    <header className="sticky top-0 z-30 h-16 border-b border-border bg-card/95 backdrop-blur supports-[backdrop-filter]:bg-card/85">
      <div className="flex h-full min-w-0 items-center justify-between gap-2 px-3 sm:gap-4 sm:px-5 lg:px-6">
        <div className="flex min-w-0 items-center gap-2 sm:gap-3">
          <button
            type="button"
            onClick={onMenuClick}
            className="rounded-lg p-2 text-foreground transition-colors hover:bg-secondary focus:outline-none focus:ring-2 focus:ring-primary lg:hidden"
            aria-label="Open navigation"
          >
            <svg className="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>

          <div className="min-w-0">
            <h2 className="truncate text-sm font-semibold leading-tight text-foreground sm:text-base">{title}</h2>
            {subtitle && <p className="mt-0.5 hidden max-w-xl truncate text-xs text-muted-foreground sm:block">{subtitle}</p>}
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
          <div className="hidden max-w-[13rem] text-right md:block">
            <p className="truncate text-sm font-medium text-foreground">{user?.name}</p>
            <p className="truncate text-xs text-muted-foreground">{roleLabel}</p>
          </div>

          <div ref={userMenu} className="relative">
            <button
              type="button"
              onClick={() => setMenuOpen((open) => !open)}
              className="flex h-9 w-9 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 sm:h-10 sm:w-10"
              aria-label="Open account menu"
              aria-expanded={menuOpen}
            >
              {user?.name?.charAt(0).toUpperCase() || 'U'}
            </button>

            <div
              className={`absolute right-0 mt-2 w-52 origin-top-right rounded-lg border border-border bg-card p-1 shadow-xl transition ${
                menuOpen ? 'visible scale-100 opacity-100' : 'invisible scale-95 opacity-0'
              }`}
            >
              <div className="border-b border-border px-3 py-2 md:hidden">
                <p className="truncate text-sm font-medium text-foreground">{user?.name}</p>
                <p className="truncate text-xs text-muted-foreground">{roleLabel}</p>
              </div>
              <button type="button" onClick={() => goTo('/profile')} className="w-full rounded-md px-3 py-2 text-left text-sm text-foreground transition-colors hover:bg-secondary">
                Profile
              </button>
              <button type="button" onClick={() => goTo('/settings')} className="w-full rounded-md px-3 py-2 text-left text-sm text-foreground transition-colors hover:bg-secondary">
                Settings
              </button>
              <hr className="my-1 border-border" />
              <button type="button" onClick={handleLogout} className="w-full rounded-md px-3 py-2 text-left text-sm text-red-600 transition-colors hover:bg-secondary">
                Log out
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>
  );
};
