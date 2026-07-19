import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { Card, CardHeader, PageLoading, Badge } from '../components/ui/Surfaces';
import { Input, Label, Select, FormRow } from '../components/ui/Field';
import Button from '../components/ui/Button';
import { db, fn, billing } from '../lib/api';
import { useToast } from '../context/ToastContext';

const emptyWa = { api_url: '', app_key: '', auth_key: '', is_active: 1 };
const emptyAi = {
  enabled: false, provider: 'gemini', model: '', api_key: '',
  feat_summary: false, feat_customer_reg: false, feat_order_draft: false,
  feat_delivery_reminder: false, feat_complaints: false, feat_unregistered_alert: false,
};

function WhatsAppConnectionCard() {
  const [form, setForm] = useState(emptyWa);
  const [existingId, setExistingId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  useEffect(() => {
    (async () => {
      try {
        const rows = await db.select('whatsapp_api_settings', { order: [{ column: 'updated_at', direction: 'desc' }] });
        const row = (rows || [])[0];
        if (row) {
          setExistingId(row.id);
          setForm({ api_url: row.api_url || '', app_key: row.app_key || '', auth_key: row.auth_key || '', is_active: row.is_active ? 1 : 0 });
        }
      } catch (err) {
        toast.error(err.message || 'تعذر تحميل إعدادات واتساب');
      } finally {
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (existingId) {
        await db.update('whatsapp_api_settings', form, { id: existingId });
      } else {
        const res = await db.insert('whatsapp_api_settings', form);
        if (res?.id) setExistingId(res.id);
      }
      toast.success('تم حفظ ربط واتساب');
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ الإعدادات');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Card className="max-w-xl"><PageLoading /></Card>;

  return (
    <Card className="max-w-xl">
      <CardHeader
        title="ربط واتساب"
        subtitle="أدخل بيانات حساب WaSender (أو مزوّد مشابه) الخاص بوكالتك — نفس البيانات الموجودة في لوحة تحكم حسابك هناك"
      />
      <form onSubmit={handleSave} className="space-y-4">
        <FormRow label="رابط API (API URL)" required>
          <Input required dir="ltr" value={form.api_url} onChange={(e) => setForm({ ...form, api_url: e.target.value })} placeholder="https://your-wasender.com/api/wa/create-message" />
        </FormRow>
        <FormRow label="مفتاح التطبيق (App Key)" required>
          <Input required dir="ltr" value={form.app_key} onChange={(e) => setForm({ ...form, app_key: e.target.value })} />
        </FormRow>
        <FormRow label="مفتاح المصادقة (Auth Key)">
          <Input dir="ltr" value={form.auth_key} onChange={(e) => setForm({ ...form, auth_key: e.target.value })} />
        </FormRow>
        <label className="flex items-center gap-2 text-sm text-ink">
          <input type="checkbox" checked={!!form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked ? 1 : 0 })} className="h-4 w-4 rounded border-line text-accent focus:ring-accent" />
          تفعيل هذا الاتصال
        </label>
        <Button type="submit" loading={saving}>حفظ إعدادات واتساب</Button>
      </form>
    </Card>
  );
}

function AiSettingsCard() {
  const [form, setForm] = useState(emptyAi);
  const [hasKey, setHasKey] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  useEffect(() => {
    (async () => {
      try {
        const { settings } = await fn.call('ai-settings-get');
        if (settings) {
          setForm({
            enabled: !!Number(settings.enabled),
            provider: settings.provider || 'gemini',
            model: settings.model || '',
            api_key: '',
            feat_summary: !!Number(settings.feat_summary),
            feat_customer_reg: !!Number(settings.feat_customer_reg),
            feat_order_draft: !!Number(settings.feat_order_draft),
            feat_delivery_reminder: !!Number(settings.feat_delivery_reminder),
            feat_complaints: !!Number(settings.feat_complaints),
            feat_unregistered_alert: !!Number(settings.feat_unregistered_alert),
          });
          setHasKey(!!settings.has_api_key);
        }
      } catch (err) {
        toast.error(err.message || 'تعذر تحميل إعدادات الذكاء الاصطناعي');
      } finally {
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const toggle = (key) => (e) => setForm({ ...form, [key]: e.target.checked });

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await fn.call('ai-settings-save', form);
      toast.success('تم حفظ إعدادات الذكاء الاصطناعي');
      if (form.api_key) setHasKey(true);
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ الإعدادات');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <Card className="max-w-xl"><PageLoading /></Card>;

  const features = [
    ['feat_summary', 'تلخيص الطلبات تلقائيًا'],
    ['feat_customer_reg', 'تسجيل عملاء جدد من واتساب'],
    ['feat_order_draft', 'صياغة طلبات من محادثة العميل'],
    ['feat_delivery_reminder', 'تذكير مواعيد التسليم'],
    ['feat_complaints', 'رصد الشكاوى تلقائيًا'],
    ['feat_unregistered_alert', 'تنبيه عند تواصل عميل غير مسجّل'],
  ];

  return (
    <Card className="max-w-xl">
      <CardHeader title="الذكاء الاصطناعي" subtitle="فعّل مساعد الذكاء الاصطناعي لأتمتة مهام واتساب المتكررة" />
      <form onSubmit={handleSave} className="space-y-4">
        <label className="flex items-center gap-2 text-sm font-medium text-ink">
          <input type="checkbox" checked={form.enabled} onChange={toggle('enabled')} className="h-4 w-4 rounded border-line text-accent focus:ring-accent" />
          تفعيل مساعد الذكاء الاصطناعي
        </label>

        <div className="grid grid-cols-2 gap-4">
          <FormRow label="المزوّد">
            <Select value={form.provider} onChange={(e) => setForm({ ...form, provider: e.target.value })}>
              <option value="gemini">Gemini</option>
              <option value="openai">OpenAI</option>
              <option value="groq">Groq</option>
              <option value="deepseek">DeepSeek</option>
            </Select>
          </FormRow>
          <FormRow label="النموذج (اختياري)">
            <Input value={form.model} onChange={(e) => setForm({ ...form, model: e.target.value })} placeholder="افتراضي" />
          </FormRow>
        </div>

        <FormRow label={hasKey ? 'مفتاح API (محفوظ — اتركه فارغًا للإبقاء عليه)' : 'مفتاح API'}>
          <Input dir="ltr" type="password" value={form.api_key} onChange={(e) => setForm({ ...form, api_key: e.target.value })} placeholder={hasKey ? '••••••••' : ''} />
        </FormRow>

        <div>
          <Label>الميزات المفعّلة</Label>
          <div className="mt-1 space-y-2">
            {features.map(([key, label]) => (
              <label key={key} className="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" checked={form[key]} onChange={toggle(key)} className="h-4 w-4 rounded border-line text-accent focus:ring-accent" />
                {label}
              </label>
            ))}
          </div>
        </div>

        <Button type="submit" loading={saving}>حفظ إعدادات الذكاء الاصطناعي</Button>
      </form>
    </Card>
  );
}

const STATUS_LABEL = { trial: 'تجريبي', active: 'نشط', past_due: 'متأخر الدفع', suspended: 'موقوف', cancelled: 'ملغي' };
const STATUS_TONE = { trial: 'warning', active: 'success', past_due: 'warning', suspended: 'danger', cancelled: 'danger' };

function BillingCard() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const toast = useToast();

  useEffect(() => {
    billing
      .status()
      .then(setData)
      .catch((err) => toast.error(err.message || 'تعذر تحميل حالة الاشتراك'))
      .finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (loading) return <Card className="max-w-xl"><PageLoading /></Card>;
  const tenant = data?.tenant;

  return (
    <Card className="max-w-xl">
      <CardHeader title="الاشتراك والفوترة" />
      {tenant ? (
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-sm text-ink-soft">الباقة الحالية</span>
            <span className="font-medium text-ink">{tenant.plan_name || '—'}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-sm text-ink-soft">الحالة</span>
            <Badge tone={STATUS_TONE[tenant.status] || 'neutral'}>{STATUS_LABEL[tenant.status] || tenant.status}</Badge>
          </div>
          {tenant.status === 'trial' && tenant.trial_ends_at && (
            <div className="flex items-center justify-between">
              <span className="text-sm text-ink-soft">تنتهي التجربة في</span>
              <span className="text-sm text-ink">{String(tenant.trial_ends_at).slice(0, 10)}</span>
            </div>
          )}
          <Link to="/pricing">
            <Button variant="outline" className="mt-2 w-full">
              {tenant.status === 'active' ? 'تغيير الباقة' : 'الاشتراك الآن'}
            </Button>
          </Link>
        </div>
      ) : (
        <p className="text-sm text-ink-soft">لا توجد بيانات اشتراك بعد.</p>
      )}
    </Card>
  );
}

export default function Settings() {
  const { user } = useAuth();

  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl font-extrabold text-ink">الإعدادات</h1>
        <p className="mt-1 text-sm text-ink-soft">إدارة حسابك وربط الخدمات الخاصة بوكالتك</p>
      </div>

      <Card className="max-w-xl">
        <CardHeader title="الملف الشخصي" />
        <div className="space-y-4">
          <div>
            <Label>الاسم الكامل</Label>
            <Input value={user?.full_name || ''} disabled />
          </div>
          <div>
            <Label>البريد الإلكتروني</Label>
            <Input value={user?.email || ''} disabled />
          </div>
          <div>
            <Label>الدور</Label>
            <Input value={user?.role === 'platform_admin' ? 'مالك المنصة' : 'عضو'} disabled />
          </div>
        </div>
      </Card>

      <WhatsAppConnectionCard />
      <AiSettingsCard />
      <BillingCard />
    </div>
  );
}
