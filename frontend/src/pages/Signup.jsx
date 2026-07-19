import { useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { Building2 } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { Input, Label } from '../components/ui/Field';
import Button from '../components/ui/Button';

export default function Signup() {
  const { signUp } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const planId = location.state?.planId;
  const [form, setForm] = useState({ agency_name: '', full_name: '', email: '', password: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const update = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    if (form.password.length < 8) {
      setError('كلمة المرور يجب أن تكون 8 أحرف على الأقل');
      return;
    }
    setLoading(true);
    try {
      await signUp(form.email, form.password, {
        agency_name: form.agency_name,
        full_name: form.full_name,
      });
      if (planId) {
        navigate('/billing/checkout', { state: { planId } });
      } else {
        navigate('/');
      }
    } catch (err) {
      setError(err.message || 'تعذر إنشاء الحساب');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-ink px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-accent text-white">
            <Building2 className="h-6 w-6" />
          </div>
          <h1 className="font-display text-xl font-extrabold text-white">إنشاء وكالة جديدة</h1>
          <p className="mt-1 text-sm text-white/50">ابدأ فترتك التجريبية المجانية الآن</p>
        </div>

        <form onSubmit={handleSubmit} className="rounded-2xl bg-white p-6 shadow-xl">
          {error && <div className="mb-4 rounded-lg bg-danger-light px-3 py-2.5 text-sm text-danger">{error}</div>}
          <div className="mb-4">
            <Label required>اسم الوكالة</Label>
            <Input required value={form.agency_name} onChange={update('agency_name')} placeholder="وكالة الإبداع للدعاية والإعلان" />
          </div>
          <div className="mb-4">
            <Label required>اسمك الكامل</Label>
            <Input required value={form.full_name} onChange={update('full_name')} placeholder="محمد أحمد" />
          </div>
          <div className="mb-4">
            <Label required>البريد الإلكتروني</Label>
            <Input type="email" required value={form.email} onChange={update('email')} placeholder="you@agency.com" />
          </div>
          <div className="mb-6">
            <Label required>كلمة المرور</Label>
            <Input type="password" required value={form.password} onChange={update('password')} placeholder="8 أحرف على الأقل" />
          </div>
          <Button type="submit" className="w-full" loading={loading}>
            إنشاء الحساب
          </Button>
        </form>

        <p className="mt-5 text-center text-sm text-white/60">
          لديك حساب بالفعل؟{' '}
          <Link to="/login" className="font-medium text-white hover:underline">
            سجّل الدخول
          </Link>
        </p>
      </div>
    </div>
  );
}
