import { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { Category, ServiceItem } from '../types';

const CATEGORY_META: Record<string, { name: string; icon: string }> = {
  pharmacies: { name: 'الصيدليات', icon: '💊' },
  doctors: { name: 'الأطباء', icon: '🩺' },
  stations: { name: 'الكازيات', icon: '⛽' },
  transport: { name: 'النقل', icon: '🚌' },
};

export default function AdminItems() {
  const { slug = 'pharmacies' } = useParams();
  const [params, setParams] = useSearchParams();
  const q = params.get('q') ?? '';
  const [items, setItems] = useState<ServiceItem[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);
    const qs = new URLSearchParams({ category: slug, q });
    api.get<ServiceItem[]>(`/admin/items.php?${qs.toString()}`, true)
      .then(setItems)
      .catch(() => setItems([]))
      .finally(() => setLoading(false));
  }, [slug, q]);

  useEffect(() => { load(); }, [load]);

  const doAction = async (action: string, id: number) => {
    if (action === 'delete' && !confirm('تأكيد حذف هذا العنصر نهائياً؟')) return;
    await api.post('/admin/items.php', { action, id }, true);
    load();
  };

  const meta = CATEGORY_META[slug] ?? { name: slug, icon: '📋' };

  return (
    <>
      <div className="admin-topbar">
        <h1>{meta.icon} {meta.name} ({items.length})</h1>
        <Link className="btn" to={`/admin/items/${slug}/new`}>+ إضافة جديد</Link>
      </div>

      <form style={{ marginBottom: 16, display: 'flex', gap: 8 }} onSubmit={(e) => e.preventDefault()}>
        <input
          className="field search-input"
          placeholder="ابحث بالاسم أو الحي..."
          value={q}
          onChange={(e) => {
            const next = new URLSearchParams(params);
            if (e.target.value) next.set('q', e.target.value); else next.delete('q');
            setParams(next);
          }}
        />
      </form>

      <div className="table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>الاسم</th>
              <th>الحي</th>
              <th>المنطقة</th>
              <th>الهاتف</th>
              {slug === 'doctors' && <th>الاختصاص</th>}
              {slug === 'stations' && <><th>الوقود</th><th>الحالة</th></>}
              {slug === 'transport' && <><th>خط السير</th><th>الحالة</th></>}
              {slug === 'pharmacies' && <th>مناوبة</th>}
              <th>إجراءات</th>
            </tr>
          </thead>
          <tbody>
            {!loading && items.length === 0 && (
              <tr><td colSpan={9} style={{ textAlign: 'center', color: 'var(--muted)' }}>لا توجد سجلات بعد.</td></tr>
            )}
            {items.map((it) => (
              <tr key={it.id}>
                <td>{it.name}</td>
                <td>{it.neighborhood}</td>
                <td>{it.area_name ? `${it.area_type} ${it.area_name}` : '—'}</td>
                <td>{it.phone || '—'}</td>
                {slug === 'doctors' && <td>{it.specialty || '—'}</td>}
                {slug === 'stations' && (
                  <>
                    <td>{it.fuel_types || '—'}</td>
                    <td>
                      <button className={`badge ${it.is_open ? 'open' : 'closed'}`} style={{ border: 'none', cursor: 'pointer' }} onClick={() => doAction('toggle_open', it.id)}>
                        {it.is_open ? 'تعمل' : 'متوقفة'}
                      </button>
                    </td>
                  </>
                )}
                {slug === 'transport' && (
                  <>
                    <td>{it.route || '—'}</td>
                    <td>
                      <button className={`badge ${it.is_open ? 'open' : 'closed'}`} style={{ border: 'none', cursor: 'pointer' }} onClick={() => doAction('toggle_open', it.id)}>
                        {it.is_open ? 'يعمل' : 'متوقف'}
                      </button>
                    </td>
                  </>
                )}
                {slug === 'pharmacies' && (
                  <td>
                    <button className="badge" style={{ border: '1px solid var(--border)', cursor: 'pointer', background: it.on_duty ? '#fff4de' : undefined, color: it.on_duty ? 'var(--warn)' : undefined }} onClick={() => doAction('toggle_duty', it.id)}>
                      {it.on_duty ? 'مناوبة ✓' : '—'}
                    </button>
                  </td>
                )}
                <td className="actions-cell">
                  <Link className="btn small secondary" to={`/admin/items/${slug}/${it.id}`}>تعديل</Link>
                  <button className="btn small danger" onClick={() => doAction('delete', it.id)}>حذف</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
