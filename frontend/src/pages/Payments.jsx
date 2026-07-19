import { useEffect, useState, useCallback } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { db } from '../lib/api';
import { Card } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

const PAYMENT_TYPES = ['نقدي', 'تحويل بنكي', 'شبكة', 'أخرى'];
const emptyForm = { order_id: '', amount: '', payment_type: 'نقدي', payment_date: new Date().toISOString().slice(0, 10) };

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

export default function Payments() {
  const [rows, setRows] = useState([]);
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [payments, ords, custs] = await Promise.all([
        db.select('payments', { order: [{ column: 'created_at', direction: 'desc' }] }),
        db.select('orders', { columns: 'id,order_number,customer_id' }),
        db.select('customers', { columns: 'id,name' }),
      ]);
      const orderById = Object.fromEntries((ords || []).map((o) => [o.id, o]));
      const custById = Object.fromEntries((custs || []).map((c) => [c.id, c]));
      const enriched = (payments || []).map((p) => {
        const order = orderById[p.order_id];
        const customer = order ? custById[order.customer_id] : null;
        return { ...p, order_number: order?.order_number, customer_name: customer?.name };
      });
      setRows(enriched);
      setOrders(ords || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل المدفوعات');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setForm(emptyForm);
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await db.insert('payments', { ...form, amount: Number(form.amount) });
      toast.success('تم تسجيل الدفعة');
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر تسجيل الدفعة');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (row) => {
    if (!confirm('حذف هذه الدفعة؟')) return;
    try {
      await db.remove('payments', { id: row.id });
      toast.success('تم حذف الدفعة');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حذف الدفعة');
    }
  };

  const totalThisList = rows.reduce((sum, r) => sum + Number(r.amount || 0), 0);

  const columns = [
    { key: 'order_number', header: 'رقم الطلب', render: (r) => r.order_number || '—' },
    { key: 'customer_name', header: 'العميل', render: (r) => r.customer_name || '—' },
    { key: 'amount', header: 'المبلغ', className: 'tabular font-medium', render: (r) => formatSar(r.amount) },
    { key: 'payment_type', header: 'طريقة الدفع' },
    { key: 'payment_date', header: 'التاريخ' },
    {
      key: 'actions',
      header: '',
      className: 'text-left',
      render: (r) => (
        <button onClick={() => handleDelete(r)} className="rounded-lg p-1.5 text-ink-soft hover:bg-danger-light hover:text-danger" aria-label="حذف">
          <Trash2 className="h-4 w-4" />
        </button>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="font-display text-2xl font-extrabold text-ink">المدفوعات</h1>
          <p className="mt-1 text-sm text-ink-soft tabular">إجمالي المعروض: {formatSar(totalThisList)}</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          تسجيل دفعة
        </Button>
      </div>

      <Card>
        <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا توجد مدفوعات بعد" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="تسجيل دفعة جديدة"
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          <FormRow label="الطلب" required>
            <Select required value={form.order_id} onChange={(e) => setForm({ ...form, order_id: e.target.value })}>
              <option value="">اختر طلبًا</option>
              {orders.map((o) => (
                <option key={o.id} value={o.id}>{o.order_number}</option>
              ))}
            </Select>
          </FormRow>
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="المبلغ (ر.س)" required>
              <Input type="number" min="0" step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </FormRow>
            <FormRow label="طريقة الدفع">
              <Select value={form.payment_type} onChange={(e) => setForm({ ...form, payment_type: e.target.value })}>
                {PAYMENT_TYPES.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </Select>
            </FormRow>
          </div>
          <FormRow label="تاريخ الدفع">
            <Input type="date" value={form.payment_date} onChange={(e) => setForm({ ...form, payment_date: e.target.value })} />
          </FormRow>
        </form>
      </Modal>
    </div>
  );
}
