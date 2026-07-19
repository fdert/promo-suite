import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Check, Building2 } from 'lucide-react';
import { billing } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import Button from '../components/ui/Button';
import { PageLoading } from '../components/ui/Surfaces';

const FEATURE_LABELS = {
  whatsapp: 'ربط واتساب وإرسال/استقبال الرسائل',
  ai: 'مساعد الذكاء الاصطناعي',
  priority_support: 'دعم فني ذو أولوية',
};

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA')} ر.س`;
}

export default function Pricing() {
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const { user } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    billing
      .plans()
      .then((res) => setPlans(res?.plans || []))
      .finally(() => setLoading(false));
  }, []);

  const handleChoose = (plan) => {
    if (user) {
      navigate('/billing/checkout', { state: { planId: plan.id } });
    } else {
      navigate('/signup', { state: { planId: plan.id } });
    }
  };

  return (
    <div className="min-h-screen bg-ink">
      <header className="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
        <Link to="/" className="flex items-center gap-2.5 text-white">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent">
            <Building2 className="h-5 w-5" />
          </div>
          <span className="font-display font-extrabold">نظام الوكالة</span>
        </Link>
        <Link to={user ? '/' : '/login'}>
          <Button variant="outline" size="sm" className="border-white/20 bg-transparent text-white hover:bg-white/10">
            {user ? 'لوحة التحكم' : 'تسجيل الدخول'}
          </Button>
        </Link>
      </header>

      <div className="mx-auto max-w-5xl px-6 pb-24 pt-8 text-center">
        <h1 className="font-display text-3xl font-extrabold text-white sm:text-4xl">باقة تناسب حجم وكالتك</h1>
        <p className="mx-auto mt-3 max-w-lg text-white/60">
          كل الباقات تشمل فترة تجريبية 14 يومًا مجانًا. يمكنك الترقية أو التخفيض في أي وقت.
        </p>

        {loading ? (
          <div className="mt-16"><PageLoading /></div>
        ) : (
          <div className="mt-14 grid grid-cols-1 gap-6 sm:grid-cols-3">
            {plans.map((plan, idx) => {
              const featured = idx === 1;
              const features = Object.entries(plan.features || {}).filter(([, v]) => v);
              return (
                <div
                  key={plan.id}
                  className={`rounded-2xl p-6 text-start ${
                    featured ? 'bg-accent text-white shadow-2xl' : 'bg-white/5 text-white ring-1 ring-white/10'
                  }`}
                >
                  <p className="font-display text-lg font-bold">{plan.name}</p>
                  <p className="mt-3">
                    <span className="font-display text-3xl font-extrabold">{formatSar(plan.price_monthly)}</span>
                    <span className={featured ? 'text-white/80' : 'text-white/50'}> / شهريًا</span>
                  </p>
                  <ul className="mt-5 space-y-2 text-sm">
                    <li className="flex items-center gap-2">
                      <Check className="h-4 w-4 shrink-0" />
                      {plan.max_users ? `حتى ${plan.max_users} مستخدمين` : 'مستخدمون غير محدودين'}
                    </li>
                    <li className="flex items-center gap-2">
                      <Check className="h-4 w-4 shrink-0" />
                      {plan.max_orders_per_month ? `${plan.max_orders_per_month} طلب شهريًا` : 'طلبات غير محدودة'}
                    </li>
                    {features.map(([key]) => (
                      <li key={key} className="flex items-center gap-2">
                        <Check className="h-4 w-4 shrink-0" />
                        {FEATURE_LABELS[key] || key}
                      </li>
                    ))}
                  </ul>
                  <Button
                    onClick={() => handleChoose(plan)}
                    className={`mt-6 w-full ${featured ? 'bg-white text-accent hover:bg-white/90' : ''}`}
                    variant={featured ? 'primary' : 'outline'}
                  >
                    ابدأ التجربة المجانية
                  </Button>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
