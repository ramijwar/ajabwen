import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '../api/client';
import { Area, ServiceItem } from '../types';

const CATEGORY_META: Record<string, { name: string; icon: string }> = {
  pharmacies: { name: 'الصيدليات', icon: '💊' },
  doctors: { name: 'الأطباء', icon: '🩺' },
  stations: { name: 'الكازيات', icon: '⛽' },
  transport: { name: 'النقل', icon: '🚌' },
};

const emptyForm = {
  name: '', neighborhood: '', area_id: '', phone: '', is_open: true, on_duty: false,
  specialty: '', fuel_types: '', route: '', notes: '',
};

export default function AdminItemForm() {
  const { slug = 'pharmacies', id } = useParams();
  const isNew = !id || id === 'new';
  const navigate = useNavigate();
  const [areas, setAreas] = useState<Area[]>([]);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState<string[]>([]);
  const [loading, setLoading] = useState(!isNew);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    api.get<Area[]>('/areas.php').then(setAreas).catch(() => setAreas([]));
  }, []);

  useEffect(() => {
    if (isNew) return;
    api.get<ServiceItem[]>(`/admin/items.php?category=${slug}`, true)
      .then((items) => {
        const found = items.find((i) => i.id === Number(id));
        if (found) {
          setForm({
            name: found.name, neighborhood: found.neighborhood, area_id: found.area_id ? String(found.area_id) : '',
            phone: found.phone ?? '', is_open: found.is_open, on_duty: found.on_duty,
            specialty: found.specialty ?? '', fuel_types: found.fuel_types ?? '', route: found.route ?? '', notes: found.notes ?? '',
          });
        }
      })
      .finally(() => setLoading(false));
  }, [slug, id, isNew]);

  const update = (key: keyof typeof form, value: string | boolean) => setForm((f) => ({ ...f, [key]: value } as typeof form));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: string[] = [];
    if (!form.name.trim()) errs.push('الاسم مطلوب.');
    if (!form.neighborhood.trim()) errs.push('الحي مطلوب.');
    setErrors(errs);
    if (errs.length) return;

    setSubmitting(true);
    try {
      await api.post('/admin/items.php', {
        action: isNew ? 'create' : 'update',
        id: isNew ? undefined : Number(id),
        category: slug,
        ...form,
        area_id: form.area_id || null,
      }, true);
      navigate(`/admin/items/${slug}`);
    } catch (err: any) {
      setErrors([err.message || 'حدث خطأ أثناء الحفظ.']);
    } finally {
      setSubmitting(false);
    }
  };

  const meta = CATEGORY_META[slug] ?? { name: slug, icon: '📋' };
  if (loading) return <p>جارٍ التحميل...</p>;

  return (
    <>
      <div className="admin-topbar">
        <h1>{isNew ? 'إضافة جديد' : 'تعديل'} — {meta.icon} {meta.name}</h1>
        <button className="btn secondary" onClick={() => navigate(`/admin/items/${slug}`)}>رجوع للقائمة</button>
      </div>

      {errors.length > 0 && <div className="alert error">{errors.join(' ')}</div>}

      <div className="form-card" style={{ maxWidth: 640, margin: 0 }}>
        <form onSubmit={submit}>
          <div className="form-group">
            <label>الاسم *</label>
            <input className="field" value={form.name} onChange={(e) => update('name', e.target.value)} required />
          </div>

          <div className="form-row">
            <div className="form-group">
              <label>الحي *</label>
              <input className="field" value={form.neighborhood} onChange={(e) => update('neighborhood', e.target.value)} required />
            </div>
            <div className="form-group">
              <label>المنطقة</label>
              <select className="field" value={form.area_id} onChange={(e) => update('area_id', e.target.value)}>
                <option value="">— بلا منطقة —</option>
                {areas.map((a) => <option key={a.id} value={a.id}>{a.type} {a.name}</option>)}
              </select>
            </div>
          </div>

          <div className="form-group">
            <label>رقم الهاتف</label>
            <input className="field" value={form.phone} onChange={(e) => update('phone', e.target.value)} />
          </div>

          {slug === 'doctors' && (
            <div className="form-group">
              <label>الاختصاص</label>
              <input className="field" value={form.specialty} onChange={(e) => update('specialty', e.target.value)} />
            </div>
          )}

          {slug === 'stations' && (
            <div className="form-group">
              <label>أنواع الوقود</label>
              <input className="field" value={form.fuel_types} onChange={(e) => update('fuel_types', e.target.value)} placeholder="بنزين، مازوت" />
            </div>
          )}

          {slug === 'transport' && (
            <div className="form-group">
              <label>خط السير</label>
              <input className="field" value={form.route} onChange={(e) => update('route', e.target.value)} />
            </div>
          )}

          {(slug === 'stations' || slug === 'transport') && (
            <div className="form-group checkbox-row">
              <input type="checkbox" id="is_open" checked={form.is_open} onChange={(e) => update('is_open', e.target.checked)} />
              <label htmlFor="is_open" style={{ margin: 0 }}>تعمل الآن</label>
            </div>
          )}

          {slug === 'pharmacies' && (
            <div className="form-group checkbox-row">
              <input type="checkbox" id="on_duty" checked={form.on_duty} onChange={(e) => update('on_duty', e.target.checked)} />
              <label htmlFor="on_duty" style={{ margin: 0 }}>مناوبة هذا الأسبوع</label>
            </div>
          )}

          <div className="form-group">
            <label>ملاحظات</label>
            <textarea className="field" rows={3} value={form.notes} onChange={(e) => update('notes', e.target.value)} />
          </div>

          <button className="btn" type="submit" style={{ width: '100%' }} disabled={submitting}>
            {submitting ? 'جارٍ الحفظ...' : (isNew ? 'إضافة' : 'حفظ التعديلات')}
          </button>
        </form>
      </div>
    </>
  );
}
