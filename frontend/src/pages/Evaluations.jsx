import { useEffect, useState, useCallback } from 'react';
import { Star } from 'lucide-react';
import { db } from '../lib/api';
import { Card, Badge } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import { useToast } from '../context/ToastContext';

function Stars({ value }) {
  const n = Number(value || 0);
  return (
    <div className="flex items-center gap-0.5" dir="ltr">
      {[1, 2, 3, 4, 5].map((i) => (
        <Star key={i} className={`h-3.5 w-3.5 ${i <= n ? 'fill-warning text-warning' : 'text-line'}`} />
      ))}
    </div>
  );
}

export default function Evaluations() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await db.select('evaluations', {
        columns: 'id,order_id,overall_rating,would_recommend,feedback_text,submitted_at,created_at,customers(name)',
        order: [{ column: 'created_at', direction: 'desc' }],
      });
      setRows((data || []).filter((r) => r.submitted_at || r.overall_rating));
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل التقييمات');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const avg = rows.length
    ? (rows.reduce((s, r) => s + Number(r.overall_rating || 0), 0) / rows.length).toFixed(1)
    : '—';

  const columns = [
    { key: 'customer_name', header: 'العميل', render: (r) => r.customer_name || 'غير محدد' },
    { key: 'overall_rating', header: 'التقييم العام', render: (r) => <Stars value={r.overall_rating} /> },
    {
      key: 'would_recommend', header: 'يوصي بالخدمة؟',
      render: (r) => (r.would_recommend === null || r.would_recommend === undefined ? '—' : (
        <Badge tone={Number(r.would_recommend) ? 'success' : 'danger'}>{Number(r.would_recommend) ? 'نعم' : 'لا'}</Badge>
      )),
    },
    { key: 'feedback_text', header: 'الملاحظات', render: (r) => <span className="max-w-xs truncate block">{r.feedback_text || '—'}</span> },
    { key: 'submitted_at', header: 'التاريخ', render: (r) => (r.submitted_at || r.created_at || '').slice(0, 10) },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="font-display text-2xl font-extrabold text-ink">تقييمات العملاء</h1>
          <p className="mt-1 text-sm text-ink-soft">{rows.length} تقييم مستلم</p>
        </div>
        <div className="flex items-center gap-2 rounded-xl bg-warning-light px-4 py-2">
          <Star className="h-5 w-5 fill-warning text-warning" />
          <span className="font-display text-lg font-bold text-ink">{avg}</span>
          <span className="text-sm text-ink-soft">متوسط التقييم</span>
        </div>
      </div>

      <Card>
        <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا توجد تقييمات بعد" emptyDescription="تُرسل روابط التقييم للعملاء تلقائيًا بعد اكتمال الطلبات" />
      </Card>
    </div>
  );
}
