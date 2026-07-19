import { useEffect, useState, useCallback } from 'react';
import { Plus, Pencil, Trash2, Wallet } from 'lucide-react';
import { db, fn } from '../lib/api';
import { Card, CardHeader } from '../components/ui/Surfaces';
import DataTable from '../components/ui/DataTable';
import Button from '../components/ui/Button';
import Modal from '../components/ui/Modal';
import { Input, Textarea, FormRow } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

const emptyEmployee = { full_name: '', position: '', phone: '', base_salary: '' };
const emptySalaryForm = { employee_id: '', pay_month: new Date().toISOString().slice(0, 7), bonus: '0', deductions: '0', notes: '' };

function formatSar(n) {
  return `${Number(n || 0).toLocaleString('ar-SA', { maximumFractionDigits: 0 })} ر.س`;
}

export default function Employees() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyEmployee);
  const [saving, setSaving] = useState(false);

  const [salaryModalOpen, setSalaryModalOpen] = useState(false);
  const [salaryForm, setSalaryForm] = useState(emptySalaryForm);
  const [payingFor, setPayingFor] = useState(null);
  const [paying, setPaying] = useState(false);

  const toast = useToast();

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const data = await db.select('employees', { order: [{ column: 'created_at', direction: 'desc' }] });
      setRows(data || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل الموظفين');
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
    setForm(emptyEmployee);
    setModalOpen(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setForm({
      full_name: row.full_name || '',
      position: row.position || '',
      phone: row.phone || '',
      base_salary: row.base_salary || '',
    });
    setModalOpen(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = { ...form, base_salary: form.base_salary ? Number(form.base_salary) : 0 };
      if (editing) {
        await db.update('employees', payload, { id: editing.id });
        toast.success('تم تحديث بيانات الموظف');
      } else {
        await db.insert('employees', payload);
        toast.success('تم إضافة الموظف');
      }
      setModalOpen(false);
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حفظ بيانات الموظف');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (row) => {
    if (!confirm(`حذف الموظف "${row.full_name}"؟`)) return;
    try {
      await db.remove('employees', { id: row.id });
      toast.success('تم حذف الموظف');
      load();
    } catch (err) {
      toast.error(err.message || 'تعذر حذف الموظف');
    }
  };

  const openPaySalary = (row) => {
    setPayingFor(row);
    setSalaryForm({ ...emptySalaryForm, employee_id: row.id });
    setSalaryModalOpen(true);
  };

  const handlePaySalary = async (e) => {
    e.preventDefault();
    setPaying(true);
    try {
      await fn.paySalary({
        ...salaryForm,
        bonus: Number(salaryForm.bonus || 0),
        deductions: Number(salaryForm.deductions || 0),
      });
      toast.success(`تم صرف راتب ${payingFor?.full_name}`);
      setSalaryModalOpen(false);
    } catch (err) {
      toast.error(err.message || 'فشل صرف الراتب');
    } finally {
      setPaying(false);
    }
  };

  const columns = [
    { key: 'full_name', header: 'الاسم', render: (r) => <span className="font-medium">{r.full_name}</span> },
    { key: 'position', header: 'المنصب' },
    { key: 'phone', header: 'الهاتف' },
    { key: 'base_salary', header: 'الراتب الأساسي', className: 'tabular', render: (r) => formatSar(r.base_salary) },
    {
      key: 'actions',
      header: '',
      className: 'text-left',
      render: (r) => (
        <div className="flex justify-end gap-1">
          <button onClick={() => openPaySalary(r)} className="rounded-lg p-1.5 text-ink-soft hover:bg-success-light hover:text-success" aria-label="صرف راتب">
            <Wallet className="h-4 w-4" />
          </button>
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
          <h1 className="font-display text-2xl font-extrabold text-ink">الموظفون والرواتب</h1>
          <p className="mt-1 text-sm text-ink-soft">{rows.length} موظف</p>
        </div>
        <Button onClick={openCreate}>
          <Plus className="h-4 w-4" />
          موظف جديد
        </Button>
      </div>

      <Card>
        <DataTable columns={columns} rows={rows} loading={loading} emptyTitle="لا يوجد موظفون بعد" />
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? 'تعديل بيانات الموظف' : 'موظف جديد'}
        footer={
          <>
            <Button variant="outline" onClick={() => setModalOpen(false)}>إلغاء</Button>
            <Button onClick={handleSave} loading={saving}>حفظ</Button>
          </>
        }
      >
        <form onSubmit={handleSave} className="space-y-4">
          <FormRow label="الاسم الكامل" required>
            <Input required value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} />
          </FormRow>
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="المنصب">
              <Input value={form.position} onChange={(e) => setForm({ ...form, position: e.target.value })} />
            </FormRow>
            <FormRow label="الهاتف">
              <Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            </FormRow>
          </div>
          <FormRow label="الراتب الأساسي (ر.س)">
            <Input type="number" min="0" step="0.01" value={form.base_salary} onChange={(e) => setForm({ ...form, base_salary: e.target.value })} />
          </FormRow>
        </form>
      </Modal>

      <Modal
        open={salaryModalOpen}
        onClose={() => setSalaryModalOpen(false)}
        title={`صرف راتب — ${payingFor?.full_name || ''}`}
        footer={
          <>
            <Button variant="outline" onClick={() => setSalaryModalOpen(false)}>إلغاء</Button>
            <Button onClick={handlePaySalary} loading={paying}>تأكيد الصرف</Button>
          </>
        }
      >
        <form onSubmit={handlePaySalary} className="space-y-4">
          <FormRow label="شهر الصرف" required>
            <Input type="month" required value={salaryForm.pay_month} onChange={(e) => setSalaryForm({ ...salaryForm, pay_month: e.target.value })} />
          </FormRow>
          <div className="grid grid-cols-2 gap-4">
            <FormRow label="مكافأة (ر.س)">
              <Input type="number" min="0" step="0.01" value={salaryForm.bonus} onChange={(e) => setSalaryForm({ ...salaryForm, bonus: e.target.value })} />
            </FormRow>
            <FormRow label="خصومات (ر.س)">
              <Input type="number" min="0" step="0.01" value={salaryForm.deductions} onChange={(e) => setSalaryForm({ ...salaryForm, deductions: e.target.value })} />
            </FormRow>
          </div>
          <FormRow label="ملاحظات">
            <Textarea value={salaryForm.notes} onChange={(e) => setSalaryForm({ ...salaryForm, notes: e.target.value })} />
          </FormRow>
        </form>
      </Modal>
    </div>
  );
}
