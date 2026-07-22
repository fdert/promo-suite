import { useEffect, useState, useCallback } from 'react';
import { Building2, Users2, TrendingUp, Ban, Plus, Pencil } from 'lucide-react';
import { db, platform, platformContent, uploadFile } from '../lib/api';
import { Card, CardHeader, Badge, PageLoading } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select, Textarea, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

const STATUS_LABEL = { trial: 'تجريبي', active: 'نشط', past_due: 'متأخر الدفع', suspended: 'موقوف', cancelled: 'ملغي' };
const STATUS_TONE = { trial: 'warning', active: 'success', past_due: 'warning', suspended: 'danger', cancelled: 'danger' };

function StatCard({ icon: Icon, label, value }) {
  return (
    <Card>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-ink-soft">{label}</p>
          <p className="mt-1 font-display text-2xl font-extrabold tabular text-ink">{value}</p>
        </div>
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-light text-accent-dark">
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </Card>
  );
}

function OverviewSection() {
  const [stats, setStats] = useState(null);
  const toast = useToast();

  useEffect(() => {
    platform.stats().then(setStats).catch((err) => toast.error(err.message || 'تعذر تحميل إحصائيات المنصة'));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!stats) return <PageLoading />;

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <StatCard icon={Building2} label="إجمالي الوكالات" value={stats.tenants_total} />
      <StatCard icon={TrendingUp} label="وكالات نشطة" value={stats.tenants_active} />
      <StatCard icon={Users2} label="تجريبية" value={stats.tenants_trial} />
      <StatCard icon={Ban} label="موقوفة" value={stats.tenants_suspended} />
      <div className="sm:col-span-2 lg:col-span-4">
        <Card>
          <p className="text-sm text-ink-soft">الإيراد الشهري المتكرر التقديري (MRR)</p>
          <p className="mt-1 font-display text-3xl font-extrabold tabular text-accent-dark">{formatSar(stats.mrr_estimate)}</p>
        </Card>
      </div>
    </div>
  );
}

function TenantsSection() {
  const [tenants, setTenants] = useState([]);
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [t, p] = await Promise.all([
        db.select('tenants', { order: [{ column: 'created_at', direction: 'desc' }] }),
        db.select('subscription_plans'),
      ]);
      const planById = Object.fromEntries((p || []).map((x) => [x.id, x]));
      setTenants((t || []).map((row) => ({ ...row, plan_name: planById[row.plan_id]?.name })));
      setPlans(p || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل الوكالات');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const handleStatusChange = async (tenant, status) => {
    if (!confirm(`تغيير حالة "${tenant.name}" إلى "${STATUS_LABEL[status]}"؟`)) return;
    try {
      await platform.setTenantStatus(tenant.id, status);
      toast.success('تم تحديث حالة الوكالة');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر تحديث الحالة');
    }
  };

  const handlePlanChange = async (tenant, planId) => {
    try {
      await db.update('tenants', { plan_id: planId }, { id: tenant.id });
      toast.success('تم تحديث الباقة');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر تحديث الباقة');
    }
  };

  const columns = [
    { key: 'name', header: 'الوكالة', render: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'status', header: 'الحالة', render: (r) => <Badge tone={STATUS_TONE[r.status] || 'neutral'}>{STATUS_LABEL[r.status] || r.status}</Badge> },
    {
      key: 'plan_id', header: 'الباقة',
      render: (r) => (
        <Select value={r.plan_id || ''} onChange={(e) => handlePlanChange(r, e.target.value)} className="h-8 text-xs">
          <option value="">بدون</option>
          {plans.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </Select>
      ),
    },
    { key: 'created_at', header: 'تاريخ الانضمام', render: (r) => String(r.created_at || '').slice(0, 10) },
    {
      key: 'actions', header: '', className: 'text-left',
      render: (r) => (
        <div className="flex justify-end gap-1">
          {r.status !== 'active' && (
            <Button size="sm" variant="outline" onClick={() => handleStatusChange(r, 'active')}>تفعيل</Button>
          )}
          {r.status !== 'suspended' && (
            <Button size="sm" variant="danger" onClick={() => handleStatusChange(r, 'suspended')}>إيقاف</Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <Card>
      <CardHeader title="الوكالات المشتركة" subtitle={`${tenants.length} وكالة`} />
      <DataTable columns={columns} rows={tenants} loading={loading} emptyTitle="لا توجد وكالات بعد" />
    </Card>
  );
}

const emptyPlan = { name: '', price_monthly: '', max_users: '', max_orders_per_month: '', is_active: 1 };

function PlansSection() {
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyPlan);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const rows = await db.select('subscription_plans', { order: [{ column: 'price_monthly', direction: 'asc' }] });
      setPlans(rows || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل الباقات');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyPlan);
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({
      name: row.name || '',
      price_monthly: row.price_monthly || '',
      max_users: row.max_users ?? '',
      max_orders_per_month: row.max_orders_per_month ?? '',
      is_active: row.is_active ? 1 : 0,
    });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        ...form,
        price_monthly: Number(form.price_monthly || 0),
        max_users: form.max_users === '' ? null : Number(form.max_users),
        max_orders_per_month: form.max_orders_per_month === '' ? null : Number(form.max_orders_per_month),
      };
      if (editing) {
        await db.update('subscription_plans', payload, { id: editing.id });
        toast.success('تم تحديث الباقة');
      } else {
        await db.insert('subscription_plans', payload);
        toast.success('تم إنشاء الباقة');
      }
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ الباقة');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { key: 'name', header: 'الباقة', render: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'price_monthly', header: 'السعر الشهري', className: 'tabular', render: (r) => formatSar(r.price_monthly) },
    { key: 'max_users', header: 'حد المستخدمين', render: (r) => r.max_users ?? 'غير محدود' },
    { key: 'max_orders_per_month', header: 'حد الطلبات/شهر', render: (r) => r.max_orders_per_month ?? 'غير محدود' },
    { key: 'is_active', header: 'الحالة', render: (r) => <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'مفعّلة' : 'معطّلة'}</Badge> },
    {
      key: 'actions', header: '', className: 'text-left',
      render: (r) => (
        <button onClick={() => openEdit(r)} className="rounded-lg p-1.5 text-ink-soft hover:bg-paper hover:text-ink" aria-label="تعديل">
          <Pencil className="h-4 w-4" />
        </button>
      ),
    },
  ];

  return (
    <Card>
      <CardHeader
        title="باقات الاشتراك"
        action={<Button size="sm" onClick={openCreate}><Plus className="h-4 w-4" />باقة جديدة</Button>}
      />
      <DataTable columns={columns} rows={plans} loading={loading} emptyTitle="لا توجد باقات" />

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? 'تعديل الباقة' : 'باقة جديدة'}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          <FormRow label="اسم الباقة" required>
            <Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </FormRow>
          <FormRow label="السعر الشهري (ر.س)" required>
            <Input type="number" min="0" step="0.01" required value={form.price_monthly} onChange={(e) => setForm({ ...form, price_monthly: e.target.value })} />
          </FormRow>
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="حد المستخدمين (اتركه فارغًا لغير محدود)">
              <Input type="number" min="0" value={form.max_users} onChange={(e) => setForm({ ...form, max_users: e.target.value })} />
            </FormRow>
            <FormRow label="حد الطلبات شهريًا (اتركه فارغًا لغير محدود)">
              <Input type="number" min="0" value={form.max_orders_per_month} onChange={(e) => setForm({ ...form, max_orders_per_month: e.target.value })} />
            </FormRow>
          </div>
          <label className="flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" checked={!!form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked ? 1 : 0 })} className="h-4 w-4 rounded border-line text-accent focus:ring-accent" />
            باقة مفعّلة (تظهر في صفحة الأسعار)
          </label>
        </form>
      </Modal>
    </Card>
  );
}

const CONTENT_FIELDS = [
  ['site_name', 'اسم المنصة', 'input'],
  ['hero_eyebrow', 'شارة صغيرة أعلى العنوان الرئيسي', 'input'],
  ['hero_headline_1', 'العنوان الرئيسي — السطر الأول', 'input'],
  ['hero_headline_2', 'العنوان الرئيسي — السطر الثاني', 'input'],
  ['hero_subtext', 'الوصف التعريفي أسفل العنوان', 'textarea'],
  ['hero_cta_primary', 'نص الزر الرئيسي', 'input'],
  ['hero_cta_secondary', 'نص الزر الثانوي', 'input'],
  ['hero_trial_note', 'ملاحظة أسفل الأزرار', 'input'],
  ['cta_headline', 'عنوان قسم الدعوة النهائي', 'input'],
  ['footer_text', 'نص التذييل (بعد © السنة)', 'input'],
];

function ContentSection() {
  const [content, setContent] = useState({});
  const [logoUploading, setLogoUploading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await platformContent.get();
      setContent(res?.content || {});
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل المحتوى');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const set = (key) => (e) => setContent((c) => ({ ...c, [key]: e.target.value }));

  const handleLogoUpload = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setLogoUploading(true);
    try {
      const res = await uploadFile('platform', `logo-${Date.now()}-${file.name}`, file);
      setContent((c) => ({ ...c, logo_url: res.path }));
      toast.success('تم رفع الشعار — لا تنس الحفظ');
    } catch (err) {
      toast.error(err.message || 'تعذر رفع الشعار');
    } finally {
      setLogoUploading(false);
    }
  };

  const handleSave = async () => {
    setSaving(true);
    try {
      await platformContent.save(content);
      toast.success('تم حفظ محتوى الصفحة الرئيسية');
    } catch (err) {
      toast.error(err.message || 'تعذر الحفظ');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <PageLoading />;

  return (
    <Card>
      <CardHeader
        title="محتوى الصفحة الرئيسية"
        subtitle="عدّل اسم المنصة والشعار ونصوص صفحة التعريف العامة — تنعكس فورًا بعد الحفظ"
        action={<Button onClick={handleSave} loading={saving}>حفظ التغييرات</Button>}
      />

      <div className="max-w-xl space-y-5">
        <FormRow label="شعار المنصة">
          <div className="flex items-center gap-4">
            <div className="flex h-14 w-14 items-center justify-center overflow-hidden rounded-xl border border-line bg-paper">
              {content.logo_url ? (
                <img src={content.logo_url} alt="الشعار" className="h-full w-full object-cover" />
              ) : (
                <span className="text-xs text-ink-faint">بدون</span>
              )}
            </div>
            <label className="cursor-pointer">
              <span className="inline-flex h-9 items-center rounded-lg border border-line bg-white px-3 text-sm font-medium text-ink hover:bg-paper">
                {logoUploading ? 'جارٍ الرفع...' : 'رفع شعار جديد'}
              </span>
              <input type="file" accept="image/*" className="hidden" onChange={handleLogoUpload} disabled={logoUploading} />
            </label>
          </div>
        </FormRow>

        {CONTENT_FIELDS.map(([key, label, type]) => (
          <FormRow key={key} label={label}>
            {type === 'textarea' ? (
              <Textarea value={content[key] || ''} onChange={set(key)} />
            ) : (
              <Input value={content[key] || ''} onChange={set(key)} />
            )}
          </FormRow>
        ))}

        <p className="text-xs text-ink-faint">
          تعديل قائمة الميزات والخطوات (features/steps) متاح حاليًا عبر الدعم الفني — واجهة تحرير مخصصة لها قادمة في تحديث لاحق.
        </p>
      </div>
    </Card>
  );
}

const TABS = [
  { key: 'overview', label: 'نظرة عامة' },
  { key: 'tenants', label: 'الوكالات' },
  { key: 'plans', label: 'الباقات' },
  { key: 'content', label: 'محتوى الصفحة الرئيسية' },
];

export default function PlatformAdmin() {
  const [tab, setTab] = useState('overview');

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl font-extrabold text-ink">إدارة المنصة</h1>
        <p className="mt-1 text-sm text-ink-soft">إدارة الوكالات المشتركة والباقات على مستوى المنصة بالكامل</p>
      </div>

      <div className="flex gap-2 overflow-x-auto border-b border-line">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`shrink-0 px-4 py-2.5 text-sm font-medium transition-colors ${
              tab === t.key ? 'border-b-2 border-accent text-accent-dark' : 'text-ink-soft hover:text-ink'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <OverviewSection />}
      {tab === 'tenants' && <TenantsSection />}
      {tab === 'plans' && <PlansSection />}
      {tab === 'content' && <ContentSection />}
    </div>
  );
}
