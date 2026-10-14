import { useState } from 'react';
import { CalendarClock, Loader2, X } from 'lucide-react';
import api, { getErrorMessage } from '@/services/api';

type EmployeeOption = { id: string; name: string };

export function ManualAttendanceModal({ employees, onClose, onSaved }: { employees: EmployeeOption[]; onClose: () => void; onSaved: () => Promise<void> }): React.JSX.Element {
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
  const [form, setForm] = useState({ employee_id: employees[0]?.id || '', work_date: today, time_in: '08:00', time_out: '17:00', reason: '' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const save = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    setBusy(true);
    try {
      await api.post('/staff-attendance/manual', form);
      await onSaved();
    } catch (caught) {
      setError(getErrorMessage(caught));
    } finally {
      setBusy(false);
    }
  };

  return <div className="fixed inset-0 z-[180] grid place-items-center bg-black/70 p-3"><section className="max-h-[94vh] w-full max-w-md overflow-auto rounded-2xl bg-card p-5 shadow-2xl"><div className="mb-4 flex items-center justify-between gap-3"><h2 className="text-lg font-bold text-foreground">Add manual attendance</h2><button onClick={onClose} className="rounded-full border border-border p-2"><X className="h-4 w-4"/></button></div><form onSubmit={event => void save(event)} className="space-y-3">{error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-950/30 dark:text-rose-200">{error}</p>}<p className="text-sm text-muted-foreground">Use only for a missed punch. The employee, Manila date and times, administrator, and reason are recorded for audit.</p><label className="block text-sm text-foreground">Employee<select required autoFocus value={form.employee_id} onChange={event => setForm({ ...form, employee_id: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2"><option value="" disabled>Select employee</option>{employees.map(employee => <option key={employee.id} value={employee.id}>{employee.name}</option>)}</select></label><label className="block text-sm text-foreground">Attendance date<input required type="date" max={today} value={form.work_date} onChange={event => setForm({ ...form, work_date: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2"/></label><div className="grid grid-cols-2 gap-3"><label className="text-sm text-foreground">Time in<input required type="time" value={form.time_in} onChange={event => setForm({ ...form, time_in: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2"/></label><label className="text-sm text-foreground">Time out<input required type="time" value={form.time_out} onChange={event => setForm({ ...form, time_out: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2"/></label></div><label className="block text-sm text-foreground">Reason<textarea required minLength={10} maxLength={500} rows={3} value={form.reason} onChange={event => setForm({ ...form, reason: event.target.value })} placeholder="Why is this attendance being entered manually?" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2"/></label><div className="flex justify-end gap-2 pt-2"><button type="button" onClick={onClose} className="rounded-lg border border-border px-4 py-2 text-sm font-semibold">Cancel</button><button disabled={busy || !form.employee_id || form.reason.trim().length < 10} type="submit" className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">{busy ? <Loader2 className="h-4 w-4 animate-spin"/> : <CalendarClock className="h-4 w-4"/>}{busy ? 'Saving…' : 'Save attendance'}</button></div></form></section></div>;
}
