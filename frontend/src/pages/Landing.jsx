import { useEffect, useState } from 'react';
import { Link, Navigate } from 'react-router-dom';
import {
  Building2, Users, MessageCircle, Wallet,
  CalendarClock, BarChart3, ShieldCheck, Check, Sparkles,
} from 'lucide-react';
import { billing, platformContent } from '../lib/api';
import { useAuth } from '../context/AuthContext';
import Button from '../components/ui/Button';

const ICONS = { Users, MessageCircle, Wallet, CalendarClock, BarChart3, ShieldCheck };

const DEFAULTS = {
  site_name: 'نظام الوكالة',
  logo_url: '',
  hero_eyebrow: 'منصة SaaS لوكالات الدعاية والإعلان',
  hero_headline_1: 'كل عمليات وكالتك الإعلانية،',
  hero_headline_2: 'في مكان واحد ذكي',
  hero_subtext: 'عملاء وطلبات ومدفوعات وواتساب وتقارير — نظام سحابي واحد مبني خصيصًا لوكالات الدعاية والإعلان.',
  hero_cta_primary: 'ابدأ تجربتك المجانية',
  hero_cta_secondary: 'عرض الباقات',
  hero_trial_note: '١٤ يومًا مجانًا، بدون بطاقة ائتمان',
  features: [
    { icon: 'Users', title: 'إدارة العملاء والطلبات', desc: 'سجل كامل لكل عميل وطلباته وحالته، من الاستفسار حتى التسليم.' },
    { icon: 'MessageCircle', title: 'واتساب مدمج', desc: 'راسل عملاءك واستقبل رسائلهم من داخل النظام مباشرة.' },
    { icon: 'Wallet', title: 'المدفوعات والتقسيط', desc: 'تتبّع كل دفعة، وأنشئ خطط تقسيط لعملائك بضغطة زر.' },
    { icon: 'CalendarClock', title: 'الرواتب والمصروفات', desc: 'إدارة كاملة لموظفيك ورواتبهم ومصروفات وكالتك.' },
    { icon: 'BarChart3', title: 'تقارير لحظية', desc: 'تقرير مالي يومي وتقارير رواتب تصلك تلقائيًا عبر واتساب.' },
    { icon: 'ShieldCheck', title: 'بياناتك معزولة وآمنة', desc: 'كل وكالة في بيئة مستقلة تمامًا عن غيرها.' },
  ],
  steps: [
    { n: '١', title: 'أنشئ وكالتك', desc: 'سجّل في دقيقتين وابدأ فترتك التجريبية فورًا.' },
    { n: '٢', title: 'اربط واتساب', desc: 'اربط حساب واتساب أعمال الخاص بوكالتك بضغطات قليلة.' },
    { n: '٣', title: 'ابدأ العمل', desc: 'أضف عملاءك وطلباتك، وسيتولى النظام الباقي.' },
  ],
  cta_headline: 'جاهز تنظّم عمل وكالتك من اليوم؟',
  footer_text: 'جميع الحقوق محفوظة',
};

function useContent() {
  const [content, setContent] = useState(DEFAULTS);

  useEffect(() => {
    platformContent
      .get()
      .then((res) => {
        const c = res?.content || {};
        setContent({
          ...DEFAULTS,
          ...c,
          features: Array.isArray(c.features) && c.features.length ? c.features : DEFAULTS.features,
          steps: Array.isArray(c.steps) && c.steps.length ? c.steps : DEFAULTS.steps,
        });
      })
      .catch(() => {});
  }, []);

  return content;
}

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA')} ر.س`;
}

function PublicNav({ content }) {
  return (
    <header className="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
      <Link to="/" className="flex items-center gap-2.5">
        {content.logo_url ? (
          <img src={content.logo_url} alt={content.site_name} className="h-9 w-9 rounded-xl object-cover" />
        ) : (
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-white">
            <Building2 className="h-5 w-5" />
          </div>
        )}
        <span className="font-display font-extrabold text-white">{content.site_name}</span>
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

function Hero({ content }) {
  return (
    <section className="relative overflow-hidden bg-ink pb-24 pt-8">
      <div
        className="pointer-events-none absolute -top-40 right-1/4 h-[32rem] w-[32rem] rounded-full bg-accent/10 blur-[120px]"
        aria-hidden="true"
      />
      <PublicNav content={content} />
      <div className="relative mx-auto grid max-w-6xl grid-cols-1 items-center gap-14 px-6 pt-16 lg:grid-cols-2">
        <div>
          <span className="animate-fade-up inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/70">
            <Sparkles className="h-3.5 w-3.5 text-accent" />
            {content.hero_eyebrow}
          </span>
          <h1
            className="animate-fade-up mt-5 font-display text-4xl font-extrabold leading-[1.15] text-white lg:text-[3.25rem]"
            style={{ animationDelay: '80ms' }}
          >
            {content.hero_headline_1}
            <br />
            {content.hero_headline_2}
          </h1>
          <p className="animate-fade-up mt-5 max-w-md text-lg leading-relaxed text-white/60" style={{ animationDelay: '140ms' }}>
            {content.hero_subtext}
          </p>
          <div className="animate-fade-up mt-8 flex flex-wrap items-center gap-3" style={{ animationDelay: '200ms' }}>
            <Link to="/signup">
              <Button size="lg">{content.hero_cta_primary}</Button>
            </Link>
            <Link to="/pricing">
              <Button size="lg" variant="outline" className="border-white/20 bg-transparent text-white hover:bg-white/10">
                {content.hero_cta_secondary}
              </Button>
            </Link>
          </div>
          <p className="animate-fade-up mt-4 text-sm text-white/40" style={{ animationDelay: '240ms' }}>
            {content.hero_trial_note}
          </p>
        </div>

        <div className="animate-fade-up relative" style={{ animationDelay: '160ms' }}>
          <div className="absolute -inset-8 rounded-[2.5rem] bg-accent/10 blur-3xl" aria-hidden="true" />
          <div className="relative overflow-hidden rounded-2xl border border-white/10 bg-paper shadow-[0_30px_80px_-20px_rgba(0,0,0,0.6)]">
            <div className="flex items-center justify-between border-b border-line bg-white px-4 py-3">
              <div className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-full bg-danger/40" />
                <span className="h-2.5 w-2.5 rounded-full bg-warning/40" />
                <span className="h-2.5 w-2.5 rounded-full bg-success/40" />
              </div>
              <span className="flex items-center gap-1.5 text-[10px] text-ink-faint">
                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" />
                متصل الآن
              </span>
            </div>
            <div className="p-5">
              <div className="mb-4 grid grid-cols-3 gap-3">
                {[
                  { label: 'العملاء', value: '128', tone: 'text-accent-dark' },
                  { label: 'الطلبات النشطة', value: '34', tone: 'text-success' },
                  { label: 'إيرادات الشهر', value: formatSar(42500), tone: 'text-ink' },
                ].map((k) => (
                  <div key={k.label} className="rounded-xl border border-line bg-white p-3">
                    <p className="text-[11px] text-ink-soft">{k.label}</p>
                    <p className={`mt-1 font-display text-sm font-bold tabular ${k.tone}`}>{k.value}</p>
                  </div>
                ))}
              </div>
              <div className="rounded-xl border border-line bg-white p-4">
                <p className="mb-3 text-xs font-medium text-ink-soft">الطلبات الأخيرة</p>
                <div className="space-y-2.5">
                  {[
                    ['تصميم هوية بصرية', 'مكتمل', 'success'],
                    ['حملة إعلانية ممولة', 'قيد التنفيذ', 'accent'],
                    ['تصوير منتجات', 'جاهز للتسليم', 'warning'],
                  ].map(([name, status, tone]) => (
                    <div key={name} className="flex items-center justify-between text-xs">
                      <span className="text-ink">{name}</span>
                      <span
                        className={`rounded-full px-2 py-0.5 ${
                          tone === 'success' ? 'bg-success-light text-success' : tone === 'warning' ? 'bg-warning-light text-warning' : 'bg-accent-light text-accent-dark'
                        }`}
                      >
                        {status}
                      </span>
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

function Features({ content }) {
  return (
    <section className="bg-paper py-20">
      <div className="mx-auto max-w-6xl px-6">
        <h2 className="max-w-md font-display text-3xl font-extrabold text-ink">
          كل ما تحتاجه وكالتك، بلا تشتت بين برامج متفرقة
        </h2>

        <div className="mt-10 grid grid-cols-1 gap-4 md:grid-cols-3">
          {content.features.map((f, i) => {
            const Icon = ICONS[f.icon] || Users;
            return (
              <div
                key={f.title}
                className={`rounded-2xl border border-line bg-white p-6 transition-shadow hover:shadow-lg ${i === 0 ? 'md:col-span-2 md:bg-ink md:text-white md:hover:shadow-2xl' : ''}`}
              >
                <div className={`mb-4 flex h-11 w-11 items-center justify-center rounded-xl ${i === 0 ? 'bg-accent text-white' : 'bg-accent-light text-accent-dark'}`}>
                  <Icon className="h-5 w-5" />
                </div>
                <h3 className={`font-display text-lg font-bold ${i === 0 ? 'md:text-white' : 'text-ink'}`}>{f.title}</h3>
                <p className={`mt-2 text-sm leading-relaxed ${i === 0 ? 'md:text-white/60' : 'text-ink-soft'}`}>{f.desc}</p>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

function HowItWorks({ content }) {
  return (
    <section className="bg-white py-20">
      <div className="mx-auto max-w-6xl px-6">
        <h2 className="font-display text-3xl font-extrabold text-ink">تبدأ العمل خلال دقائق</h2>
        <div className="relative mt-12 grid grid-cols-1 gap-10 md:grid-cols-3">
          <div className="absolute right-0 top-6 hidden h-px w-full bg-line md:block" aria-hidden="true" />
          {content.steps.map((s) => (
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

  if (!plans.length) return null;

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
                className={`rounded-2xl p-6 transition-transform hover:-translate-y-1 ${featured ? 'bg-ink text-white shadow-xl' : 'border border-line bg-white'}`}
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

function CtaBanner({ content }) {
  return (
    <section className="relative overflow-hidden bg-accent py-16">
      <div className="pointer-events-none absolute -left-16 -top-16 h-64 w-64 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
      <div className="relative mx-auto flex max-w-4xl flex-col items-center gap-6 px-6 text-center">
        <h2 className="font-display text-2xl font-extrabold text-white sm:text-3xl">{content.cta_headline}</h2>
        <Link to="/signup">
          <Button size="lg" className="bg-white text-accent-dark hover:bg-white/90">
            {content.hero_cta_primary}
          </Button>
        </Link>
      </div>
    </section>
  );
}

function Footer({ content }) {
  return (
    <footer className="bg-ink py-10">
      <div className="mx-auto flex max-w-6xl flex-col items-center gap-4 px-6 text-center sm:flex-row sm:justify-between sm:text-right">
        <div className="flex items-center gap-2.5">
          {content.logo_url ? (
            <img src={content.logo_url} alt={content.site_name} className="h-8 w-8 rounded-lg object-cover" />
          ) : (
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-white">
              <Building2 className="h-4 w-4" />
            </div>
          )}
          <span className="font-display font-bold text-white">{content.site_name}</span>
        </div>
        <p className="text-sm text-white/40">© {new Date().getFullYear()} {content.footer_text}</p>
      </div>
    </footer>
  );
}

export default function Landing() {
  const { user, checkingSession } = useAuth();
  const content = useContent();

  if (checkingSession) return null; // avoid a flash-redirect while verifying a cached login
  if (user) return <Navigate to="/dashboard" replace />;

  return (
    <div className="min-h-screen bg-paper" dir="rtl">
      <Hero content={content} />
      <Features content={content} />
      <HowItWorks content={content} />
      <PricingTeaser />
      <CtaBanner content={content} />
      <Footer content={content} />
    </div>
  );
}
