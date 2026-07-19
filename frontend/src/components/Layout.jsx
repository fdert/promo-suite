import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import {
  LayoutDashboard, Users, ShoppingBag, Wallet, Receipt, Settings,
  LogOut, Menu, X, Building2, MessageCircle, UserCog, CalendarClock,
  FileBarChart, Star, ShieldCheck,
} from 'lucide-react';
import { useAuth } from '../context/AuthContext';

const NAV_ITEMS = [
  { to: '/', label: 'الرئيسية', icon: LayoutDashboard, end: true },
  { to: '/customers', label: 'العملاء', icon: Users },
  { to: '/orders', label: 'الطلبات', icon: ShoppingBag },
  { to: '/payments', label: 'المدفوعات', icon: Wallet },
  { to: '/installments', label: 'التقسيط', icon: CalendarClock },
  { to: '/expenses', label: 'المصروفات', icon: Receipt },
  { to: '/employees', label: 'الموظفون', icon: UserCog },
  { to: '/whatsapp', label: 'واتساب', icon: MessageCircle },
  { to: '/reports', label: 'التقارير', icon: FileBarChart },
  { to: '/evaluations', label: 'التقييمات', icon: Star },
  { to: '/settings', label: 'الإعدادات', icon: Settings },
];

function SidebarContent({ onNavigate }) {
  const { user, signOut, isPlatformAdmin } = useAuth();
  const navigate = useNavigate();

  const navItems = isPlatformAdmin
    ? [...NAV_ITEMS, { to: '/admin', label: 'إدارة المنصة', icon: ShieldCheck }]
    : NAV_ITEMS;

  const handleSignOut = async () => {
    await signOut();
    navigate('/login');
  };

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2.5 px-5 py-6">
        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-white">
          <Building2 className="h-5 w-5" />
        </div>
        <div>
          <p className="font-display text-sm font-extrabold leading-tight text-white">نظام الوكالة</p>
          <p className="text-xs text-white/50">إدارة أعمال شاملة</p>
        </div>
      </div>

      <nav className="flex-1 space-y-1 px-3">
        {navItems.map(({ to, label, icon: Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            onClick={onNavigate}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
                isActive ? 'bg-accent text-white' : 'text-white/70 hover:bg-white/10 hover:text-white'
              }`
            }
          >
            <Icon className="h-4 w-4 shrink-0" />
            {label}
          </NavLink>
        ))}
      </nav>

      <div className="border-t border-white/10 px-3 py-4">
        <div className="mb-2 flex items-center gap-2.5 rounded-xl px-3 py-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-xs font-bold text-white">
            {(user?.full_name || user?.email || '?').slice(0, 1).toUpperCase()}
          </div>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-white">{user?.full_name || user?.email}</p>
            <p className="truncate text-xs text-white/50">{user?.role === 'platform_admin' ? 'مالك المنصة' : 'عضو'}</p>
          </div>
        </div>
        <button
          onClick={handleSignOut}
          className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-white/70 hover:bg-white/10 hover:text-white"
        >
          <LogOut className="h-4 w-4" />
          تسجيل الخروج
        </button>
      </div>
    </div>
  );
}

export default function Layout() {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="flex min-h-screen bg-paper">
      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 bg-ink lg:block">
        <div className="fixed h-screen w-64">
          <SidebarContent />
        </div>
      </aside>

      {/* Mobile sidebar */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-black/40" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 right-0 w-72 bg-ink">
            <SidebarContent onNavigate={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-line bg-white px-4 lg:hidden">
          <button onClick={() => setMobileOpen(true)} className="rounded-lg p-2 hover:bg-paper" aria-label="القائمة">
            <Menu className="h-5 w-5" />
          </button>
          <p className="font-display font-bold">نظام الوكالة</p>
        </header>
        <main className="flex-1 p-4 lg:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
