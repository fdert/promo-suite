import { useEffect, useState, useCallback } from 'react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { db } from '../lib/api';
import { Card } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select, FormRow } from '../components/ui/Field';
import { Badge } from '../components/ui/Surfaces';
import { useToast } from '../context/ToastContext';

const STATUSES = ['قيد التنفيذ', 'جاهز للتسليم', 'مكتمل', 'ملغي'];
const STATUS_TONE = { 'قيد التنفيذ': 'accent', 'جاهز للتسليم': 'warning', 'مكتمل': 'success', 'ملغي': 'danger' };

const emptyForm = { customer_id: '', service_name: '', status: 'قيد التنفيذ', total_amount: '', delivery_date: '', notes: '' };

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

export default function Orders() {
  const [rows, setRows] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [orders, custs] = await Promise.all([
        db.select('orders', {
          columns: 'id,order_number,status,total_amount,paid_amount,delivery_date,customer_id,customers(name,whatsapp)',
          order: [{ column: 'created_at', direction: 'desc' }],
        }),
        db.select('customers', { columns: 'id,name' }),
      ]);
      setRows(orders || []);
      setCustomers(custs || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل الطلبات');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = async () => {
    setEditing(null);
    let orderNumber = '';
    try {
      const r = await db.rpc('generate_order_number');
      orderNumber = r?.result || '';
    } catch {
      /* non-fatal: backend will still accept the insert without it */
    }
    setForm({ ...emptyForm, order_number: orderNumber });
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({
      customer_id: row.customer_id || '',
      service_name: '',
      status: row.status || 'قيد التنفيذ',
      total_amount: row.total_amount || '',
      delivery_date: row.delivery_date || '',
      notes: row.notes || '',
    });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = { ...form, total_amount: form.total_amount ? Number(form.total_amount) : 0 };
      if (editing) {
        await db.update('orders', payload, { id: editing.id });
        toast.success('تم تحديث الطلب');
      } else {
        await db.insert('orders', payload);
        toast.success('تم إنشاء الطلب');
      }
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ الطلب');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (row) => {
    if (!confirm(`حذف الطلب "${row.order_number}"؟`)) return;
    try {
      await db.remove('orders', { id: row.id });
      toast.success('تم حذف الطلب');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حذف الطلب');
    }
  };

  const columns = [
    { key: 'order_number', header: 'رقم الطلب', render: (r) => <span className="font-medium">{r.order_number}</span> },
    { key: 'customer_name', header: 'العميل', render: (r) => r.customer_name || '—' },
    { key: 'status', header: 'الحالة', render: (r) => <Badge tone={STATUS_TONE[r.status] || 'neutral'}>{r.status}</Badge> },
    { key: 'total_amount', header: 'القيمة', className: 'tabular', render: (r) => formatSar(r.total_amount) },
    { key: 'paid_amount', header: 'المدفوع', className: 'tabular', render: (r) => formatSar(r.paid_amount) },
    { key: 'delivery_date', header: 'التسليم', render: (r) => r.delivery_date || '—' },
    {
      key: 'actions',
      header: '',
      className: 'text-left',
      render: (r) => (
        <div className="flex justify-end gap-1">
          <button onClick={() => openEdit(r)} className="rounded-lg p-1.5 text-ink-soft hover:bg-paper hover:text-ink" aria-label="تعديل">
            <Pencil className="h-4 w-4" />
          </button>
          <button onClick={() => handleDelete(r)} className="rounded-lg p-1.5 text-ink-soft hover:bg-danger-light hover:text-danger" aria-label="حذف">
            <Trash2 className="h-4 w-4" />
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="font-display text-2xl font-extrabold text-ink">الطلبات</h1>
          <p className="mt-1 text-sm text-ink-soft">{rows.length} طلب</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          طلب جديد
        </Button>
      </div>

      <Card>
        <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا توجد طلبات بعد" emptyDescription="أنشئ أول طلب لعميل" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? 'تعديل الطلب' : 'طلب جديد'}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          {form.order_number && (
            <FormRow label="رقم الطلب">
              <Input value={form.order_number} disabled />
            </FormRow>
          )}
          <FormRow label="العميل" required>
            <Select required value={form.customer_id} onChange={(e) => setForm({ ...form, customer_id: e.target.value })}>
              <option value="">اختر عميلاً</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </Select>
          </FormRow>
          {!editing && (
            <FormRow label="نوع الخدمة">
              <Input value={form.service_name} onChange={(e) => setForm({ ...form, service_name: e.target.value })} placeholder="مثال: تصميم شعار" />
            </FormRow>
          )}
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="الحالة">
              <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                {STATUSES.map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </FormRow>
            <FormRow label="القيمة الإجمالية (ر.س)">
              <Input type="number" min="0" step="0.01" value={form.total_amount} onChange={(e) => setForm({ ...form, total_amount: e.target.value })} />
            </FormRow>
          </div>
          <FormRow label="تاريخ التسليم المتوقع">
            <Input type="date" value={form.delivery_date} onChange={(e) => setForm({ ...form, delivery_date: e.target.value })} />
          </FormRow>
        </form>
      </Modal>
    </div>
  );
}
