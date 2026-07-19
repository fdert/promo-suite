import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { CheckCircle2, XCircle } from 'lucide-react';
import { billing } from '../lib/api';
import { Card, PageLoading } from '../components/ui/Surfaces';
import Button from '../components/ui/Button';

export default function CheckoutComplete() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [status, setStatus] = useState('checking'); // checking | success | failed
  const [message, setMessage] = useState('');

  useEffect(() => {
    const subscriptionId = params.get('subscription_id');
    const paymentId = params.get('id'); // Moyasar appends the payment id as ?id=...
    const gatewayStatus = params.get('status');

    if (!subscriptionId || !paymentId) {
      setStatus('failed');
      setMessage('رابط تأكيد الدفع غير مكتمل');
      return;
    }
    if (gatewayStatus === 'failed') {
      setStatus('failed');
      setMessage('فشلت عملية الدفع لدى البنك. حاول مرة أخرى ببطاقة أخرى.');
      return;
    }

    billing
      .confirmPayment(subscriptionId, paymentId)
      .then((res) => {
        if (res?.ok) {
          setStatus('success');
        } else {
          setStatus('failed');
          setMessage(res?.message || 'لم يتم تأكيد الدفع');
        }
      })
      .catch((err) => {
        setStatus('failed');
        setMessage(err.message || 'تعذر تأكيد الدفع');
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="flex min-h-screen items-center justify-center bg-paper px-4">
      <Card className="w-full max-w-sm text-center">
        {status === 'checking' && (
          <>
            <PageLoading />
            <p className="text-sm text-ink-soft">جارٍ تأكيد عملية الدفع...</p>
          </>
        )}
        {status === 'success' && (
          <>
            <CheckCircle2 className="mx-auto mb-3 h-12 w-12 text-success" />
            <p className="font-display text-lg font-bold text-ink">تم تفعيل اشتراكك بنجاح</p>
            <p className="mt-1 text-sm text-ink-soft">يمكنك الآن الاستفادة من كامل ميزات باقتك</p>
            <Button className="mt-5 w-full" onClick={() => navigate('/')}>الذهاب للوحة التحكم</Button>
          </>
        )}
        {status === 'failed' && (
          <>
            <XCircle className="mx-auto mb-3 h-12 w-12 text-danger" />
            <p className="font-display text-lg font-bold text-ink">تعذر إتمام الدفع</p>
            <p className="mt-1 text-sm text-ink-soft">{message}</p>
            <Link to="/pricing">
              <Button variant="outline" className="mt-5 w-full">المحاولة مرة أخرى</Button>
            </Link>
          </>
        )}
      </Card>
    </div>
  );
}
