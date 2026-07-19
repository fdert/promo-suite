import { useState } from 'react';
import { Send, FileBarChart } from 'lucide-react';
import { fn } from '../lib/api';
import { Card, CardHeader, Badge } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import { Select, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

const MONTHS = Array.from({ length: 12 }, (_, i) => String(i + 1).padStart(2, '0'));
const YEARS = Array.from({ length: 5 }, (_, i) => String(new Date().getFullYear() - i));

function DailyReportCard() {
  const [sending, setSending] = useState(false);
  const [result, setResult] = useState(null);
  const toast = useToast();

  const handleSend = async () => {
    setSending(true);
    setResult(null);
    try {
      const res = await fn.dailyFinancialReport(false);
      setResult(res?.totals || null);
      toast.success('تم إرسال التقرير المالي اليومي عبر واتساب');
    } catch (err) {
      toast.error(err.message || 'تعذر إرسال التقرير');
    } finally {
      setSending(false);
    }
  };

  return (
    <Card>
      <CardHeader
        title="التقرير المالي اليومي"
        subtitle="يُرسل ملخصًا بمدفوعات ومصروفات وطلبات اليوم إلى رقم واتساب المتابعة المحدد في الإعدادات"
        action={
          <Button onClick={handleSend} loading={sending} size="sm">
            <Send className="h-4 w-4" />
            إرسال الآن
          </Button>
        }
      />
      {result && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">المدفوعات</p>
            <p className="tabular font-display font-bold text-success">{formatSar(result.totalPayments)}</p>
          </div>
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">المصروفات</p>
            <p className="tabular font-display font-bold text-danger">{formatSar(result.totalExpenses)}</p>
          </div>
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">صافي الربح</p>
            <p className="tabular font-display font-bold text-ink">{formatSar(result.netProfit)}</p>
          </div>
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">طلبات جديدة</p>
            <p className="font-display font-bold text-ink">{result.newOrdersCount}</p>
          </div>
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">مكتملة اليوم</p>
            <p className="font-display font-bold text-ink">{result.completedOrdersCount}</p>
          </div>
          <div className="rounded-xl bg-paper p-3">
            <p className="text-xs text-ink-soft">تسليم متأخر</p>
            <p className="font-display font-bold text-ink">{result.delayedOrdersCount}</p>
          </div>
        </div>
      )}
    </Card>
  );
}

function SalaryReportCard() {
  const now = new Date();
  const [month, setMonth] = useState(String(now.getMonth() + 1).padStart(2, '0'));
  const [year, setYear] = useState(String(now.getFullYear()));
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const toast = useToast();

  const handleLoad = async () => {
    setLoading(true);
    try {
      const res = await fn.salaryReport({ month: `${year}-${month}` });
      setRows(res?.rows || []);
      setTotal(res?.total || 0);
      setLoaded(true);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل تقرير الرواتب');
    } finally {
      setLoading(false);
    }
  };

  const columns = [
    { key: 'full_name', header: 'الموظف' },
    { key: 'position', header: 'المنصب' },
    { key: 'pay_month', header: 'الشهر' },
    { key: 'bonus', header: 'مكافأة', className: 'tabular', render: (r) => formatSar(r.bonus) },
    { key: 'deductions', header: 'خصومات', className: 'tabular', render: (r) => formatSar(r.deductions) },
    { key: 'total_amount', header: 'الصافي', className: 'tabular font-medium', render: (r) => formatSar(r.total_amount) },
  ];

  return (
    <Card>
      <CardHeader title="تقرير الرواتب" subtitle="عرض الرواتب المصروفة خلال شهر محدد" />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <FormRow label="الشهر">
          <Select value={month} onChange={(e) => setMonth(e.target.value)}>
            {MONTHS.map((m) => (
              <option key={m} value={m}>{m}</option>
            ))}
          </Select>
        </FormRow>
        <FormRow label="السنة">
          <Select value={year} onChange={(e) => setYear(e.target.value)}>
            {YEARS.map((y) => (
              <option key={y} value={y}>{y}</option>
            ))}
          </Select>
        </FormRow>
        <Button onClick={handleLoad} loading={loading} variant="outline">
          <FileBarChart className="h-4 w-4" />
          عرض التقرير
        </Button>
        {loaded && (
          <Badge tone="accent">الإجمالي: {formatSar(total)}</Badge>
        )}
      </div>
      {loaded && <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا توجد رواتب مصروفة في هذا الشهر" />}
    </Card>
  );
}

export default function Reports() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="font-display text-2xl font-extrabold text-ink">التقارير</h1>
        <p className="mt-1 text-sm text-ink-soft">تقارير مالية وإدارية لوكالتك</p>
      </div>
      <DailyReportCard />
      <SalaryReportCard />
    </div>
  );
}
