import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api } from '../api/client';
import { Area, AreaType } from '../types';

const TYPES: AreaType[] = ['مدينة', 'بلدة', 'قرية'];

export default function AdminAreas() {
  const [areas, setAreas] = useState<Area[]>([]);
  const [loading, setLoading] = useState(true);
  const [name, setName] = useState('');
  const [type, setType] = useState<AreaType>('قرية');
  const [editId, setEditId] = useState<number | null>(null);
  const [error, setError] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    api.get<Area[]>('/admin/areas.php', true).then(setAreas).catch(() => setAreas([])).finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const resetForm = () => { setName(''); setType('قرية'); setEditId(null); setError(''); };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    if (!name.trim()) { setError('اسم المنطقة مطلوب.'); return; }
    try {
      await api.post('/admin/areas.php', {
        action: editId ? 'update' : 'create',
        id: editId ?? undefined,
        name: name.trim(),
        type,
      }, true);
      resetForm();
      load();
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء الحفظ.');
    }
  };

  const startEdit = (a: Area) => { setEditId(a.id); setName(a.name); setType(a.type); setError(''); };

  const remove = async (a: Area) => {
    if (!confirm(`تأكيد حذف "${a.name}"؟`)) return;
    try {
      await api.post('/admin/areas.php', { action: 'delete', id: a.id }, true);
      load();
    } catch (err: any) {
      alert(err.message || 'تعذّر الحذف.');
    }
  };

  return (
    <>
      <div className="admin-topbar"><h1>🗺️ إدارة المناطق (قرى / بلدات / مدن)</h1></div>

      <div className="form-card" style={{ maxWidth: 480, margin: '0 0 24px' }}>
        <h3 style={{ marginTop: 0 }}>{editId ? 'تعديل منطقة' : 'إضافة منطقة جديدة'}</h3>
        {error && <div className="alert error">{error}</div>}
        <form onSubmit={submit}>
          <div className="form-row">
            <div className="form-group">
              <label>الاسم *</label>
              <input className="field" value={name} onChange={(e) => setName(e.target.value)} placeholder="مثال: عندان" required />
            </div>
            <div className="form-group">
              <label>النوع *</label>
              <select className="field" value={type} onChange={(e) => setType(e.target.value as AreaType)}>
                {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
          </div>
          <button className="btn" type="submit" style={{ width: '100%' }}>{editId ? 'حفظ التعديل' : 'إضافة المنطقة'}</button>
          {editId && <button type="button" className="btn secondary" style={{ width: '100%', marginTop: 8 }} onClick={resetForm}>إلغاء التعديل</button>}
        </form>
      </div>

      <div className="table-wrap">
        <table className="admin-table">
          <thead><tr><th>الاسم</th><th>النوع</th><th>عدد الخدمات المرتبطة</th><th>إجراءات</th></tr></thead>
          <tbody>
            {!loading && areas.length === 0 && (
              <tr><td colSpan={4} style={{ textAlign: 'center', color: 'var(--muted)' }}>لا توجد مناطق بعد.</td></tr>
            )}
            {areas.map((a) => (
              <tr key={a.id}>
                <td>{a.name}</td>
                <td>{a.type}</td>
                <td>{a.usage_count ?? 0}</td>
                <td className="actions-cell">
                  <button className="btn small secondary" onClick={() => startEdit(a)}>تعديل</button>
                  <button className="btn small danger" onClick={() => remove(a)}>حذف</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
