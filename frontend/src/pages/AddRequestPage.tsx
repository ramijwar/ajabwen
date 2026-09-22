import { FormEvent, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { Area, Category } from '../types';

export default function AddRequestPage() {
  const [params, setParams] = useSearchParams();
  const [cats, setCats] = useState<Category[]>([]);
  const [areas, setAreas] = useState<Area[]>([]);
  const [slug, setSlug] = useState(params.get('cat') || 'pharmacies');
  const [form, setForm] = useState({
    name: '', neighborhood: '', area_id: '', phone: '', specialty: '', fuel_types: '', route: '', notes: '',
  });
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    api.get<Category[]>('/categories.php').then(setCats).catch(() => setCats([]));
    api.get<Area[]>('/areas.php').then(setAreas).catch(() => setAreas([]));
  }, []);

  const onCategoryChange = (value: string) => {
    setSlug(value);
    setParams({ cat: value });
  };

  const update = (key: keyof typeof form, value: string) => setForm((f) => ({ ...f, [key]: value }));

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    if (!form.name.trim() || !form.neighborhood.trim()) {
      setError('الاسم والحي مطلوبان.');
      return;
    }
    setSubmitting(true);
    try {
      await api.post('/requests.php', { category: slug, ...form, area_id: form.area_id || null });
      setSuccess(true);
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء الإرسال.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <section className="page-head">
        <div className="container">
          <h1>طلب إضافة خدمة</h1>
          <p className="sub">أرسل بيانات الخدمة وسيقوم فريق الإدارة بمراجعتها ونشرها بعد التأكد منها.</p>
        </div>
      </section>

      <section className="section" style={{ paddingTop: 0 }}>
        <div className="container">
          <div className="form-card">
            {success ? (
              <>
                <div className="alert success">تم إرسال طلبك بنجاح! سيتم مراجعته من قبل فريقنا قبل نشره. شكراً لمساهمتك 🌿</div>
                <Link className="btn secondary" to="/">العودة للرئيسية</Link>
              </>
            ) : (
              <form onSubmit={submit}>
                {error && <div className="alert error">{error}</div>}

                <div className="form-group">
                  <label>نوع الخدمة</label>
                  <select className="field" value={slug} onChange={(e) => onCategoryChange(e.target.value)}>
                    {cats.map((c) => <option key={c.slug} value={c.slug}>{c.icon} {c.name}</option>)}
                  </select>
                </div>

                <div className="form-group">
                  <label>الاسم *</label>
                  <input className="field" value={form.name} onChange={(e) => update('name', e.target.value)} placeholder="مثال: صيدلية النور" required />
                </div>

                <div className="form-row">
                  <div className="form-group">
                    <label>الحي *</label>
                    <input className="field" value={form.neighborhood} onChange={(e) => update('neighborhood', e.target.value)} placeholder="مثال: الشهباء" required />
                  </div>
                  <div className="form-group">
                    <label>المنطقة</label>
                    <select className="field" value={form.area_id} onChange={(e) => update('area_id', e.target.value)}>
                      <option value="">— اختر —</option>
                      {areas.map((a) => <option key={a.id} value={a.id}>{a.type} {a.name}</option>)}
                    </select>
                  </div>
                </div>

                <div className="form-group">
                  <label>رقم الهاتف</label>
                  <input className="field" value={form.phone} onChange={(e) => update('phone', e.target.value)} placeholder="09xxxxxxxx" />
                </div>

                {slug === 'doctors' && (
                  <div className="form-group">
                    <label>الاختصاص</label>
                    <input className="field" value={form.specialty} onChange={(e) => update('specialty', e.target.value)} placeholder="مثال: أطفال" />
                  </div>
                )}

                {slug === 'stations' && (
                  <div className="form-group">
                    <label>أنواع الوقود المتوفرة</label>
                    <input className="field" value={form.fuel_types} onChange={(e) => update('fuel_types', e.target.value)} placeholder="مثال: بنزين، مازوت" />
                  </div>
                )}

                {slug === 'transport' && (
                  <div className="form-group">
                    <label>خط السير</label>
                    <input className="field" value={form.route} onChange={(e) => update('route', e.target.value)} placeholder="مثال: الميدان - وسط المدينة" />
                  </div>
                )}

                <div className="form-group">
                  <label>ملاحظات إضافية</label>
                  <textarea className="field" rows={3} value={form.notes} onChange={(e) => update('notes', e.target.value)} />
                </div>

                <button className="btn" type="submit" style={{ width: '100%' }} disabled={submitting}>
                  {submitting ? 'جارٍ الإرسال...' : 'إرسال الطلب'}
                </button>
              </form>
            )}
          </div>
        </div>
      </section>
    </>
  );
}
