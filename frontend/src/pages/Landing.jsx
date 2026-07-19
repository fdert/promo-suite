import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import {
  Building2, Users, MessageCircle, Wallet,
  CalendarClock, BarChart3, ShieldCheck, Check, Star,
} from 'lucide-react';
import { billing } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import Button from '../components/ui/Button';

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA')} ر.س`;
}

function PublicNav() {
  return (
    <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
      <Link to="/" className="flex items-center gap-2.5">
        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-white">
          <Building2 className="h-5 w-5" />
        </div>
        <span className="font-display font-extrabold text-white">نظام الوكالة</span>
      </Link>
      <div className="flex items-center gap-3">
        <Link to="/login" className="text-sm font-medium text-white/70 hover:text-white">
          تسجيل الدخول
        </Link>
        <Link to="/signup">
          <Button size="sm">جرّبها مجانًا</Button>
        </Link>
      </div>
    </header>
  );
}

function Hero() {
  return (
    <section className="bg-ink pb-20 pt-8">
      <PublicNav />
      <div className="mx-auto grid max-w-6xl grid-cols-1 items-center gap-12 px-6 pt-16 lg:grid-cols-2">
        {/* Content */}
        <div>
          <h1 className="font-display text-4xl font-extrabold leading-[1.15] text-white lg:text-5xl">
            كل عمليات وكالتك الإعلانية،
            <br />
            في مكان واحد ذكي
          </h1>
          <p className="mt-5 max-w-md text-lg leading-relaxed text-white/60">
            عملاء وطلبات ومدفوعات وواتساب وتقارير — نظام سحابي واحد مبني خصيصًا لوكالات الدعاية والإعلان.
          </p>
          <div className="mt-8 flex flex-wrap items-center gap-3">
            <Link to="/signup">
              <Button size="lg">ابدأ تجربتك المجانية</Button>
            </Link>
            <Link to="/pricing">
              <Button size="lg" variant="outline" className="border-white/20 bg-transparent text-white hover:bg-white/10">
                عرض الباقات
              </Button>
            </Link>
          </div>
          <p className="mt-4 text-sm text-white/40">١٤ يومًا مجانًا، بدون بطاقة ائتمان</p>
        </div>

        {/* Real product-preview mockup, built from the same design tokens as the actual app */}
        <div className="relative">
          <div className="absolute -inset-6 rounded-[2rem] bg-accent/10 blur-2xl" aria-hidden="true" />
          <div className="relative overflow-hidden rounded-2xl border border-white/10 bg-paper shadow-2xl">
            <div className="flex items-center gap-1.5 border-b border-line bg-white px-4 py-3">
              <span className="h-2.5 w-2.5 rounded-full bg-danger/40" />
              <span className="h-2.5 w-2.5 rounded-full bg-warning/40" />
              <span className="h-2.5 w-2.5 rounded-full bg-success/40" />
            </div>
            <div className="p-5">
              <div className="mb-4 grid grid-cols-3 gap-3">
                {[
                  { label: 'العملاء', value: '128', tone: 'text-accent-dark' },
                  { label: 'الطلبات النشطة', value: '34', tone: 'text-success' },
                  { label: 'إيرادات الشهر', value: '42,500 ر.س', tone: 'text-ink' },
                ].map((k) => (
                  <div key={k.label} className="rounded-xl border border-line bg-white p-3">
                    <p className="text-[11px] text-ink-soft">{k.label}</p>
                    <p className={`mt-1 font-display text-sm font-bold ${k.tone}`}>{k.value}</p>
                  </div>
                ))}
              </div>
              <div className="rounded-xl border border-line bg-white p-4">
                <p className="mb-3 text-xs font-medium text-ink-soft">الطلبات الأخيرة</p>
                <div className="space-y-2.5">
                  {[
                    ['ORD-1042', 'تصميم هوية بصرية', 'مكتمل'],
                    ['ORD-1041', 'حملة إعلانية ممولة', 'قيد التنفيذ'],
                    ['ORD-1040', 'تصوير منتجات', 'جاهز للتسليم'],
                  ].map(([id, name, status]) => (
                    <div key={id} className="flex items-center justify-between text-xs">
                      <span className="text-ink">{name}</span>
                      <span className="rounded-full bg-accent-light px-2 py-0.5 text-accent-dark">{status}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

const FEATURES = [
  { icon: Users, title: 'إدارة العملاء والطلبات', desc: 'سجل كامل لكل عميل وطلباته وحالته، من الاستفسار حتى التسليم.', big: true },
  { icon: MessageCircle, title: 'واتساب مدمج', desc: 'راسل عملاءك واستقبل رسائلهم من داخل النظام مباشرة.' },
  { icon: Wallet, title: 'المدفوعات والتقسيط', desc: 'تتبّع كل دفعة، وأنشئ خطط تقسيط لعملائك بضغطة زر.' },
  { icon: CalendarClock, title: 'الرواتب والمصروفات', desc: 'إدارة كاملة لموظفيك ورواتبهم ومصروفات وكالتك.' },
  { icon: BarChart3, title: 'تقارير لحظية', desc: 'تقرير مالي يومي وتقارير رواتب تصلك تلقائيًا عبر واتساب.' },
  { icon: ShieldCheck, title: 'بياناتك معزولة وآمنة', desc: 'كل وكالة في بيئة مستقلة تمامًا عن غيرها.' },
];

function Features() {
  return (
    <section className="bg-paper py-20">
      <div className="mx-auto max-w-6xl px-6">
        <h2 className="max-w-md font-display text-3xl font-extrabold text-ink">
          كل ما تحتاجه وكالتك، بلا تشتت بين برامج متفرقة
        </h2>

        <div className="mt-10 grid grid-cols-1 gap-4 md:grid-cols-3">
          {FEATURES.map((f, i) => (
            <div
              key={f.title}
              className={`rounded-2xl border border-line bg-white p-6 ${f.big ? 'md:col-span-2 md:row-span-1' : ''} ${i === 0 ? 'md:bg-ink md:text-white' : ''}`}
            >
              <div className={`mb-4 flex h-11 w-11 items-center justify-center rounded-xl ${i === 0 ? 'bg-accent text-white' : 'bg-accent-light text-accent-dark'}`}>
                <f.icon className="h-5 w-5" />
              </div>
              <h3 className={`font-display text-lg font-bold ${i === 0 ? 'md:text-white' : 'text-ink'}`}>{f.title}</h3>
              <p className={`mt-2 text-sm leading-relaxed ${i === 0 ? 'md:text-white/60' : 'text-ink-soft'}`}>{f.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

const STEPS = [
  { n: '١', title: 'أنشئ وكالتك', desc: 'سجّل في دقيقتين وابدأ فترتك التجريبية فورًا.' },
  { n: '٢', title: 'اربط واتساب', desc: 'اربط حساب واتساب أعمال الخاص بوكالتك بضغطات قليلة.' },
  { n: '٣', title: 'ابدأ العمل', desc: 'أضف عملاءك وطلباتك، وسيتولى النظام الباقي.' },
];

function HowItWorks() {
  return (
    <section className="bg-white py-20">
      <div className="mx-auto max-w-6xl px-6">
        <h2 className="font-display text-3xl font-extrabold text-ink">تبدأ العمل خلال دقائق</h2>
        <div className="relative mt-12 grid grid-cols-1 gap-10 md:grid-cols-3">
          <div className="absolute right-0 top-6 hidden h-px w-full bg-line md:block" aria-hidden="true" />
          {STEPS.map((s) => (
            <div key={s.n} className="relative">
              <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-ink font-display text-lg font-bold text-white">
                {s.n}
              </div>
              <h3 className="font-display text-lg font-bold text-ink">{s.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-ink-soft">{s.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

const FEATURE_LABELS = { whatsapp: 'ربط واتساب', ai: 'مساعد الذكاء الاصطناعي', priority_support: 'دعم ذو أولوية' };

function PricingTeaser() {
  const [plans, setPlans] = useState([]);

  useEffect(() => {
    billing.plans().then((res) => setPlans(res?.plans || [])).catch(() => {});
  }, []);

  return (
    <section className="bg-paper py-20">
      <div className="mx-auto max-w-6xl px-6">
        <div className="flex items-end justify-between">
          <h2 className="font-display text-3xl font-extrabold text-ink">باقات تناسب كل حجم</h2>
          <Link to="/pricing" className="hidden text-sm font-medium text-accent-dark hover:underline sm:block">
            تفاصيل كل الباقات
          </Link>
        </div>

        <div className="mt-10 grid grid-cols-1 gap-5 md:grid-cols-3">
          {plans.map((plan, idx) => {
            const featured = idx === 1;
            return (
              <div
                key={plan.id}
                className={`rounded-2xl p-6 ${featured ? 'bg-ink text-white shadow-xl' : 'border border-line bg-white'}`}
              >
                <p className="font-display font-bold">{plan.name}</p>
                <p className="mt-2">
                  <span className="font-display text-2xl font-extrabold">{formatSar(plan.price_monthly)}</span>
                  <span className={featured ? 'text-white/60' : 'text-ink-soft'}> / شهريًا</span>
                </p>
                <ul className="mt-4 space-y-1.5 text-sm">
                  {Object.entries(plan.features || {}).filter(([, v]) => v).map(([key]) => (
                    <li key={key} className="flex items-center gap-2">
                      <Check className="h-3.5 w-3.5 shrink-0" />
                      {FEATURE_LABELS[key] || key}
                    </li>
                  ))}
                </ul>
              </div>
            );
          })}
        </div>
        <Link to="/pricing" className="mt-6 block text-center text-sm font-medium text-accent-dark hover:underline sm:hidden">
          تفاصيل كل الباقات
        </Link>
      </div>
    </section>
  );
}

function CtaBanner() {
  return (
    <section className="bg-accent py-16">
      <div className="mx-auto flex max-w-4xl flex-col items-center gap-6 px-6 text-center">
        <Star className="h-8 w-8 text-white" />
        <h2 className="font-display text-2xl font-extrabold text-white sm:text-3xl">
          جاهز تنظّم عمل وكالتك من اليوم؟
        </h2>
        <Link to="/signup">
          <Button size="lg" className="bg-white text-accent-dark hover:bg-white/90">
            ابدأ تجربتك المجانية
          </Button>
        </Link>
      </div>
    </section>
  );
}

function Footer() {
  return (
    <footer className="bg-ink py-10">
      <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-6 text-center sm:flex-row sm:justify-between sm:text-right">
        <div className="flex items-center gap-2.5">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-white">
            <Building2 className="h-4 w-4" />
          </div>
          <span className="font-display font-bold text-white">نظام الوكالة</span>
        </div>
        <p className="text-sm text-white/40">© {new Date().getFullYear()} جميع الحقوق محفوظة</p>
      </div>
    </footer>
  );
}

export default function Landing() {
  const { user } = useAuth();
  if (user) return <Navigate to="/dashboard" replace />;

  return (
    <div className="min-h-screen bg-paper" dir="rtl">
      <Hero />
      <Features />
      <HowItWorks />
      <PricingTeaser />
      <CtaBanner />
      <Footer />
    </div>
  );
}
