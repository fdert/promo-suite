import { useEffect, useState } from 'react';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';
import { Users, ShoppingBag, Wallet, AlertTriangle } from 'lucide-react';
import { fn } from '../lib/api';
import { Card, CardHeader, PageLoading, Badge } from '../components/ui/Surfaces';
import { useToast } from '../context/ToastContext';

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

function Kpi({ icon: Icon, label, value, sub, tone = 'accent' }) {
  const toneClasses = {
    accent: 'bg-accent-light text-accent-dark',
    success: 'bg-success-light text-success',
    warning: 'bg-warning-light text-warning',
    danger: 'bg-danger-light text-danger',
  };
  return (
    <Card>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm text-ink-soft">{label}</p>
          <p className="mt-1 font-display text-2xl font-extrabold tabular text-ink">{value}</p>
          {sub && <p className="mt-1 text-xs text-ink-faint">{sub}</p>}
        </div>
        <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${toneClasses[tone]}`}>
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </Card>
  );
}

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const toast = useToast();

  useEffect(() => {
    let alive = true;
    fn.dashboardStats()
      .then((res) => alive && setData(res))
      .catch((err) => toast.error(err.message || 'تعذر تحميل لوحة المعلومات'))
      .finally(() => alive && setLoading(false));
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (loading) return <PageLoading />;
  if (!data) return null;

  const k = data.kpis || {};

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl font-extrabold text-ink">لوحة المعلومات</h1>
        <p className="mt-1 text-sm text-ink-soft">نظرة عامة على أداء وكالتك اليوم</p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi icon={Users} label="إجمالي العملاء" value={k.customers_total ?? 0} sub={`+${k.customers_new_month ?? 0} هذا الشهر`} />
        <Kpi icon={ShoppingBag} label="الطلبات النشطة" value={k.orders_active ?? 0} sub={`${k.orders_total ?? 0} إجمالي`} tone="success" />
        <Kpi icon={Wallet} label="إيرادات الشهر" value={formatSar(k.revenue_month)} tone="success" />
        <Kpi icon={AlertTriangle} label="متأخرات التسليم" value={k.late_deliveries ?? 0} tone="danger" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="الإيرادات مقابل المصروفات" subtitle="آخر 12 شهرًا" />
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={data.monthly || []}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E4E2DC" />
                <XAxis dataKey="ym" tick={{ fontSize: 12 }} />
                <YAxis tick={{ fontSize: 12 }} />
                <Tooltip formatter={(v) => formatSar(v)} />
                <Line type="monotone" dataKey="revenue" stroke="#E8542C" strokeWidth={2} name="الإيرادات" dot={false} />
                <Line type="monotone" dataKey="expenses" stroke="#15161C" strokeWidth={2} name="المصروفات" dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card>
          <CardHeader title="الطلبات الأخيرة" />
          <div className="space-y-3">
            {(data.recent_orders || []).slice(0, 5).map((o) => (
              <div key={o.id} className="flex items-center justify-between border-b border-line pb-3 last:border-0 last:pb-0">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-ink">{o.order_number}</p>
                  <p className="truncate text-xs text-ink-soft">{o.customer_name || 'غير محدد'}</p>
                </div>
                <Badge tone="accent">{o.status}</Badge>
              </div>
            ))}
            {(!data.recent_orders || data.recent_orders.length === 0) && (
              <p className="py-6 text-center text-sm text-ink-faint">لا توجد طلبات بعد</p>
            )}
          </div>
        </Card>
      </div>
    </div>
  );
}
