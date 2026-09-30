import { Outlet, Link, useLocation } from 'react-router';
import { Bot, LogOut, Play, Shield, UserRound, type LucideIcon } from 'lucide-react';
import { useAuth } from '../auth/useAuth';
import { LogoMark } from '../components/LogoMark';
import { Badge } from '../components/ui';
import { cn } from '../lib/utils';

interface NavItemProps {
  to: string;
  icon: LucideIcon;
  label: string;
  exact?: boolean;
}

function NavItem({ to, icon: Icon, label, exact = false }: NavItemProps) {
  const location = useLocation();
  const active = exact ? location.pathname === to : location.pathname.startsWith(to);

  return (
    <Link
      to={to}
      title={label}
      className={cn(
        'flex items-center justify-center gap-3 rounded-box px-3 py-2.5 text-sm font-medium transition mid:justify-start',
        active ? 'bg-primary/12 text-primary' : 'text-muted hover:bg-white/5 hover:text-ink',
      )}
    >
      <Icon className="h-4 w-4 shrink-0" aria-hidden />
      <span className="hidden mid:inline">{label}</span>
    </Link>
  );
}

/**
 * Application shell (user + admin): fixed sidebar (collapses to an icon bar
 * below 992px), glass topbar and a centered content area (max 1320px).
 */
export function AppLayout() {
  const { email, roles, logout } = useAuth();
  const isAdmin = roles.includes('ROLE_ADMIN');

  const handleLogout = () => {
    logout();
  };

  return (
    <div className="min-h-screen bg-app text-ink">
      {/* Sidebar */}
      <aside className="fixed inset-y-0 left-0 z-40 flex w-16 flex-col border-r border-line bg-surface-1 mid:w-sidebar">
        <div className="flex h-16 shrink-0 items-center justify-center gap-2.5 border-b border-line px-3 mid:justify-start mid:px-5">
          <LogoMark className="h-8 w-8 shrink-0 rounded-lg" />
          <span className="hidden text-sm font-semibold text-ink mid:inline">Cable Car</span>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          <NavItem to="/agent/runs" icon={Bot} label="Runs" />
          <NavItem to="/agent/runs/new" icon={Play} label="Nouvelle tâche" exact />
          <NavItem to="/profile" icon={UserRound} label="Profil" />
          {isAdmin && <NavItem to="/admin/users" icon={Shield} label="Utilisateurs" />}
        </nav>

        <div className="shrink-0 border-t border-line p-3">
          <button
            type="button"
            onClick={handleLogout}
            title="Déconnexion"
            className="flex w-full items-center justify-center gap-3 rounded-box px-3 py-2.5 text-sm font-medium text-muted transition hover:bg-danger/15 hover:text-danger mid:justify-start"
          >
            <LogOut className="h-4 w-4 shrink-0" aria-hidden />
            <span className="hidden mid:inline">Déconnexion</span>
          </button>
        </div>
      </aside>

      {/* Main column (offset by the sidebar) */}
      <div className="pl-16 mid:pl-sidebar">
        {/* Glass topbar */}
        <header className="sticky top-0 z-30 border-b border-line bg-app/70 backdrop-blur-md">
          <div className="mx-auto flex h-16 max-w-content items-center justify-between px-4 sm:px-6">
            <span className="text-sm font-semibold text-ink mid:hidden">Cable Car</span>
            <span className="hidden text-xs uppercase tracking-widest text-faint mid:block">Espace</span>

            <div className="flex items-center gap-3">
              <div className="hidden items-center gap-2 sm:flex">
                <UserRound className="h-4 w-4 text-faint" aria-hidden />
                <span className="text-sm text-muted">{email}</span>
                {isAdmin && <Badge tone="primary">Admin</Badge>}
              </div>
              <button
                type="button"
                onClick={handleLogout}
                title="Déconnexion"
                className="flex h-8 w-8 items-center justify-center rounded-box text-muted transition hover:bg-danger/15 hover:text-danger"
              >
                <LogOut className="h-4 w-4" aria-hidden />
              </button>
            </div>
          </div>
        </header>

        <main className="mx-auto max-w-content px-4 py-8 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}