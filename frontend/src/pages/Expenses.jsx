import { useEffect, useState, useCallback } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { db } from '../lib/api';
import { Card } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Select, Textarea, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

const CATEGORIES = ['إيجار', 'رواتب', 'تسويق', 'أدوات ومعدات', 'مرافق', 'أخرى'];
const emptyForm = { expense_type: 'أخرى', amount: '', expense_date: new Date().toISOString().slice(0, 10), description: '' };

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

export default function Expenses() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await db.select('expenses', { order: [{ column: 'expense_date', direction: 'desc' }] });
      setRows(data || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل المصروفات');
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
      await db.insert('expenses', { ...form, amount: Number(form.amount) });
      toast.success('تم تسجيل المصروف');
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر تسجيل المصروف');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (row) => {
    if (!confirm('حذف هذا المصروف؟')) return;
    try {
      await db.remove('expenses', { id: row.id });
      toast.success('تم حذف المصروف');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حذف المصروف');
    }
  };

  const total = rows.reduce((sum, r) => sum + Number(r.amount || 0), 0);

  const columns = [
    { key: 'expense_date', header: 'التاريخ' },
    { key: 'expense_type', header: 'التصنيف' },
    { key: 'description', header: 'الوصف', render: (r) => r.description || '—' },
    { key: 'amount', header: 'المبلغ', className: 'tabular font-medium', render: (r) => formatSar(r.amount) },
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
          <h1 className="font-display text-2xl font-extrabold text-ink">المصروفات</h1>
          <p className="mt-1 text-sm text-ink-soft tabular">إجمالي المعروض: {formatSar(total)}</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          مصروف جديد
        </Button>
      </div>

      <Card>
        <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا توجد مصروفات مسجّلة" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="تسجيل مصروف جديد"
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="التصنيف">
              <Select value={form.expense_type} onChange={(e) => setForm({ ...form, expense_type: e.target.value })}>
                {CATEGORIES.map((c) => (
                  <option key={c} value={c}>{c}</option>
                ))}
              </Select>
            </FormRow>
            <FormRow label="المبلغ (ر.س)" required>
              <Input type="number" min="0" step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </FormRow>
          </div>
          <FormRow label="التاريخ">
            <Input type="date" value={form.expense_date} onChange={(e) => setForm({ ...form, expense_date: e.target.value })} />
          </FormRow>
          <FormRow label="الوصف">
            <Textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </FormRow>
        </form>
      </Modal>
    </div>
  );
}
