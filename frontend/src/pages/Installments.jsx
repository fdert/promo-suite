import { useEffect, useState, useCallback } from 'react';
import { Plus, Search } from 'lucide-react';
import { db, fn } from '../lib/api';
import { Card, CardHeader, Badge } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

const STATUS_TONE = { active: 'accent', completed: 'success', overdue: 'danger' };
const STATUS_LABEL = { active: 'نشطة', completed: 'مكتملة', overdue: 'متأخرة' };

export default function Installments() {
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [searchResults, setSearchResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [count, setCount] = useState(3);
  const [firstDueDate, setFirstDueDate] = useState(new Date().toISOString().slice(0, 10));
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const rows = await db.select('installment_plans_summary');
      setPlans(rows || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل خطط التقسيط');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setQuery('');
    setSearchResults([]);
    setSelectedOrder(null);
    setCount(3);
    setFirstDueDate(new Date().toISOString().slice(0, 10));
    setModalOpen(true);
  };

  const handleSearch = async (e) => {
    e.preventDefault();
    setSearching(true);
    try {
      const rows = await fn.searchOrdersForInstallment(query, { unpaid_only: true, limit: 10 });
      setSearchResults(rows || []);
    } catch (err) {
      toast.error(err.message || 'تعذر البحث');
    } finally {
      setSearching(false);
    }
  };

  const handleCreatePlan = async () => {
    if (!selectedOrder) return;
    setSaving(true);
    try {
      const remaining = Number(selectedOrder.remaining_amount || 0);
      const perInstallment = Math.round((remaining / count) * 100) / 100;

      const plan = await db.insert('installment_plans', {
        order_id: selectedOrder.id,
        customer_id: selectedOrder.customer_id || null,
        total_amount: remaining,
        number_of_installments: count,
        status: 'active',
      });

      const rows = [];
      for (let i = 0; i < count; i++) {
        const due = new Date(firstDueDate);
        due.setMonth(due.getMonth() + i);
        rows.push({
          installment_plan_id: plan?.id,
          installment_number: i + 1,
          amount: i === count - 1 ? remaining - perInstallment * (count - 1) : perInstallment,
          due_date: due.toISOString().slice(0, 10),
          status: 'pending',
        });
      }
      await db.insert('installment_payments', rows);

      toast.success('تم إنشاء خطة التقسيط');
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر إنشاء خطة التقسيط');
    } finally {
      setSaving(false);
    }
  };

  const columns = [
    { key: 'order_number', header: 'رقم الطلب', render: (r) => <span className="font-medium">{r.order_number}</span> },
    { key: 'customer_name', header: 'العميل' },
    { key: 'total_amount', header: 'إجمالي الخطة', className: 'tabular', render: (r) => formatSar(r.total_amount) },
    { key: 'remaining_amount', header: 'المتبقي', className: 'tabular', render: (r) => formatSar(r.remaining_amount) },
    {
      key: 'progress', header: 'الأقساط', render: (r) => `${r.paid_installments || 0} / ${r.number_of_installments || 0}`,
    },
    { key: 'plan_status', header: 'الحالة', render: (r) => <Badge tone={STATUS_TONE[r.plan_status] || 'neutral'}>{STATUS_LABEL[r.plan_status] || r.plan_status}</Badge> },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="font-display text-2xl font-extrabold text-ink">خطط التقسيط</h1>
          <p className="mt-1 text-sm text-ink-soft">{plans.length} خطة</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          خطة تقسيط جديدة
        </Button>
      </div>

      <Card>
        <DataTable columns={columns} rows={plans} loading={loading} emptyTitle="لا توجد خطط تقسيط بعد" rowKey="order_id" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="خطة تقسيط جديدة"
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleCreatePlan} loading={saving} disabled={!selectedOrder}>إنشاء الخطة</Button>
          </>
        }
      >
        <div className="space-y-4">
          <form onSubmit={handleSearch} className="flex gap-2">
            <div className="relative flex-1">
              <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
              <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="ابحث برقم الطلب أو اسم العميل..." className="pr-9" />
            </div>
            <Button type="submit" variant="outline" loading={searching}>بحث</Button>
          </form>

          {searchResults.length > 0 && (
            <div className="max-h-48 space-y-1 overflow-y-auto scrollbar-thin rounded-xl border border-line p-2">
              {searchResults.map((o) => (
                <button
                  key={o.id}
                  onClick={() => setSelectedOrder(o)}
                  className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-start text-sm ${
                    selectedOrder?.id === o.id ? 'bg-accent-light' : 'hover:bg-paper'
                  }`}
                >
                  <span>{o.order_number} — {o.customer_name}</span>
                  <span className="tabular text-ink-soft">{formatSar(o.remaining_amount)}</span>
                </button>
              ))}
            </div>
          )}

          {selectedOrder && (
            <div className="rounded-xl border border-line bg-paper/50 p-4">
              <p className="mb-3 text-sm">
                المبلغ المتبقي على الطلب <strong>{selectedOrder.order_number}</strong>: <span className="tabular font-medium">{formatSar(selectedOrder.remaining_amount)}</span>
              </p>
              <div className="grid grid-cols-2 gap-4">
                <FormRow label="عدد الأقساط">
                  <Select value={count} onChange={(e) => setCount(Number(e.target.value))}>
                    {[2, 3, 4, 6, 12].map((n) => (
                      <option key={n} value={n}>{n} أقساط</option>
                    ))}
                  </Select>
                </FormRow>
                <FormRow label="تاريخ أول قسط">
                  <Input type="date" value={firstDueDate} onChange={(e) => setFirstDueDate(e.target.value)} />
                </FormRow>
              </div>
            </div>
          )}
        </div>
      </Modal>
    </div>
  );
}
