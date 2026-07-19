import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Building2 } from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { Input, Label } from '../components/ui/Field';
import Button from '../components/ui/Button';
import { ApiError } from '../lib/api';

export default function Login() {
  const { signIn } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      await signIn(email, password);
      navigate('/');
    } catch (err) {
      if (err instanceof ApiError && err.code === 'trial_expired') {
        setError('انتهت الفترة التجريبية لوكالتكم. يرجى الاشتراك للمتابعة.');
      } else if (err instanceof ApiError && err.code === 'tenant_suspended') {
        setError('تم إيقاف اشتراك وكالتكم. يرجى تجديد الاشتراك للمتابعة.');
      } else {
        setError(err.message || 'تعذر تسجيل الدخول');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-ink px-4">
      <div className="w-full max-w-sm">
        <div className="mb-8 flex flex-col items-center text-center">
          <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-accent text-white">
            <Building2 className="h-6 w-6" />
          </div>
          <h1 className="font-display text-xl font-extrabold text-white">تسجيل الدخول</h1>
          <p className="mt-1 text-sm text-white/50">نظام إدارة وكالات الدعاية والإعلان</p>
        </div>

        <form onSubmit={handleSubmit} className="rounded-2xl bg-white p-6 shadow-xl">
          {error && (
            <div className="mb-4 rounded-lg bg-danger-light px-3 py-2.5 text-sm text-danger">{error}</div>
          )}
          <div className="mb-4">
            <Label required>البريد الإلكتروني</Label>
            <Input type="email" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@agency.com" />
          </div>
          <div className="mb-6">
            <Label required>كلمة المرور</Label>
            <Input type="password" required value={password} onChange={(e) => setPassword(e.target.value)} placeholder="••••••••" />
          </div>
          <Button type="submit" className="w-full" loading={loading}>
            دخول
          </Button>
        </form>

        <p className="mt-5 text-center text-sm text-white/60">
          ليس لديك حساب؟{' '}
          <Link to="/signup" className="font-medium text-white hover:underline">
            أنشئ وكالتك الآن
          </Link>
        </p>
      </div>
    </div>
  );
}
