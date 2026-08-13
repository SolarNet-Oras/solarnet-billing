import React from 'react';
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

  const handleLogout = async (): Promise<void> => {
    await logout();
    navigate('/login');
  };

  return (
    <header className="h-16 bg-card border-b border-border sticky top-0 z-30">
      <div className="h-full px-4 flex items-center justify-between">
        {/* Left side - Menu button (mobile) */}
        <div className="flex items-center gap-4">
          <button
            onClick={onMenuClick}
            className="md:hidden p-2 hover:bg-secondary rounded-md text-foreground"
          >
            <svg
              className="w-6 h-6"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M4 6h16M4 12h16M4 18h16"
              />
            </svg>
          </button>

          <div className="hidden sm:block">
            <h2 className="text-base font-semibold leading-tight text-foreground">{title}</h2>
            {subtitle && <p className="mt-0.5 max-w-xl truncate text-xs text-muted-foreground">{subtitle}</p>}
          </div>
        </div>

        {/* Right side - User menu */}
        <div className="flex items-center gap-4">
          {/* User info */}
          <div className="hidden sm:block text-right">
            <p className="text-sm font-medium text-foreground">{user?.name}</p>
            <p className="text-xs text-muted-foreground">
              {user?.roles?.[0]?.display_name || 'User'}
            </p>
          </div>

          {/* User avatar/menu */}
          <div className="relative group">
            <button className="w-10 h-10 rounded-full bg-primary text-primary-foreground flex items-center justify-center font-semibold hover:opacity-90 transition-opacity">
              {user?.name?.charAt(0).toUpperCase()}
            </button>

            {/* Dropdown menu */}
            <div className="absolute right-0 mt-2 w-48 bg-card border border-border rounded-md shadow-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200">
              <div className="py-1">
                <button
                  onClick={() => navigate('/profile')}
                  className="w-full px-4 py-2 text-left text-sm text-foreground hover:bg-secondary transition-colors"
                >
                  👤 Profile
                </button>
                <button
                  onClick={() => navigate('/settings')}
                  className="w-full px-4 py-2 text-left text-sm text-foreground hover:bg-secondary transition-colors"
                >
                  ⚙️ Settings
                </button>
                <hr className="my-1 border-border" />
                <button
                  onClick={handleLogout}
                  className="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-secondary transition-colors"
                >
                  🚪 Logout
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </header>
  );
};
