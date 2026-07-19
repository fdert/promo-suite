import { useEffect, useState, useCallback } from 'react';
import { Plus, Pencil, Trash2, Search } from 'lucide-react';
import { db } from '../lib/api';
import { Card, CardHeader } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Label, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

const emptyForm = { name: '', phone: '', whatsapp: '', email: '', notes: '' };

export default function Customers() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await db.select('customers', { order: [{ column: 'created_at', direction: 'desc' }] });
      setRows(data || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل العملاء');
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({ name: row.name || '', phone: row.phone || '', whatsapp: row.whatsapp || '', email: row.email || '', notes: row.notes || '' });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (editing) {
        await db.update('customers', form, { id: editing.id });
        toast.success('تم تحديث بيانات العميل');
      } else {
        await db.insert('customers', form);
        toast.success('تم إضافة العميل');
      }
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ العميل');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (row) => {
    if (!confirm(`حذف العميل "${row.name}"؟ لا يمكن التراجع عن هذا الإجراء.`)) return;
    try {
      await db.remove('customers', { id: row.id });
      toast.success('تم حذف العميل');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حذف العميل');
    }
  };

  const filtered = rows.filter((r) => {
    const q = search.trim().toLowerCase();
    if (!q) return true;
    return [r.name, r.phone, r.whatsapp, r.email].some((v) => (v || '').toLowerCase().includes(q));
  });

  const columns = [
    { key: 'name', header: 'الاسم', render: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'phone', header: 'الهاتف' },
    { key: 'whatsapp', header: 'واتساب' },
    { key: 'email', header: 'البريد الإلكتروني' },
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
          <h1 className="font-display text-2xl font-extrabold text-ink">العملاء</h1>
          <p className="mt-1 text-sm text-ink-soft">{rows.length} عميل مسجّل</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          عميل جديد
        </Button>
      </div>

      <Card>
        <div className="mb-4 relative max-w-xs">
          <Search className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
          <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث بالاسم أو الهاتف..." className="pr-9" />
        </div>
        <DataTable columns={columns} rows={filtered} loading={loading} emptyTitle="لا يوجد عملاء بعد" emptyDescription="ابدأ بإضافة أول عميل لوكالتك" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? 'تعديل بيانات العميل' : 'عميل جديد'}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          <FormRow label="الاسم" required>
            <Input required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </FormRow>
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="الهاتف">
              <Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} placeholder="05xxxxxxxx" />
            </FormRow>
            <FormRow label="واتساب">
              <Input value={form.whatsapp} onChange={(e) => setForm({ ...form, whatsapp: e.target.value })} placeholder="9665xxxxxxxx" />
            </FormRow>
          </div>
          <FormRow label="البريد الإلكتروني">
            <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </FormRow>
        </form>
      </Modal>
    </div>
  );
}
