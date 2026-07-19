import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { billing } from '../lib/api';
import { Card, CardHeader, PageLoading } from '../components/ui/Surfaces';
import { useToast } from '../context/ToastContext';

// Moyasar's hosted payment form (Moyasar.js) — loaded from their CDN rather
// than as an npm package, per their integration docs:
// https://docs.moyasar.com/payments/hosted-payment-page
const MOYASAR_JS = 'https://cdn.moyasar.com/mpf/1.15.0/moyasar.js';
const MOYASAR_CSS = 'https://cdn.moyasar.com/mpf/1.15.0/moyasar.css';

function loadMoyasarAssets() {
  return new Promise((resolve, reject) => {
    if (window.Moyasar) return resolve();
    if (!document.querySelector(`link[href="${MOYASAR_CSS}"]`)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = MOYASAR_CSS;
      document.head.appendChild(link);
    }
    const script = document.createElement('script');
    script.src = MOYASAR_JS;
    script.onload = resolve;
    script.onerror = () => reject(new Error('تعذر تحميل بوابة الدفع'));
    document.body.appendChild(script);
  });
}

export default function Checkout() {
  const location = useLocation();
  const navigate = useNavigate();
  const toast = useToast();
  const formRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [payment, setPayment] = useState(null);

  const planId = location.state?.planId;

  useEffect(() => {
    if (!planId) {
      navigate('/pricing', { replace: true });
      return;
    }
    let alive = true;
    (async () => {
      try {
        const res = await billing.createPayment(planId);
        if (!alive) return;
        setPayment(res);
        if (!res.publishable_key) {
          toast.error('بوابة الدفع غير مُهيأة بعد من الإدارة. تواصل مع الدعم.');
          return;
        }
        await loadMoyasarAssets();
        if (!alive) return;
        window.Moyasar.init({
          element: '.mysr-form',
          amount: res.amount_halalas,
          currency: res.currency,
          description: `اشتراك ${res.plan_name}`,
          publishable_api_key: res.publishable_key,
          callback_url: res.callback_url,
          methods: ['creditcard'],
          metadata: { subscription_id: res.subscription_id },
        });
      } catch (err) {
        toast.error(err.message || 'تعذر بدء عملية الدفع');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [planId]);

  return (
    <div className="flex min-h-screen items-center justify-center bg-paper px-4 py-10">
      <Card className="w-full max-w-md">
        <CardHeader title="إتمام الاشتراك" subtitle={payment ? `باقة ${payment.plan_name}` : ''} />
        {loading && <PageLoading />}
        <div ref={formRef} className="mysr-form" />
        <p className="mt-4 text-center text-xs text-ink-faint">
          الدفع مؤمّن بالكامل عبر Moyasar — لا تُخزَّن بيانات بطاقتك على خوادمنا.
        </p>
      </Card>
    </div>
  );
}
