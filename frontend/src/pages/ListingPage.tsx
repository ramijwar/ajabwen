import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { Area, Category, ServiceItem } from '../types';

interface ListingPageProps {
  slug: 'pharmacies' | 'doctors' | 'stations' | 'transport';
  subtitle: string;
  showOpenFilter?: boolean;
  showDutyFilter?: boolean;
}

interface ItemsResponse {
  category: Category;
  total: number;
  items: ServiceItem[];
}

export default function ListingPage({ slug, subtitle, showOpenFilter, showDutyFilter }: ListingPageProps) {
  const [params, setParams] = useSearchParams();
  const [data, setData] = useState<ItemsResponse | null>(null);
  const [areas, setAreas] = useState<Area[]>([]);
  const [loading, setLoading] = useState(true);

  const q = params.get('q') ?? '';
  const areaId = params.get('area_id') ?? 'all';
  const status = params.get('status') ?? 'all';

  useEffect(() => {
    api.get<Area[]>('/areas.php').then(setAreas).catch(() => setAreas([]));
  }, []);

  useEffect(() => {
    setLoading(true);
    const qs = new URLSearchParams({ category: slug, q, area_id: areaId, status });
    api
      .get<ItemsResponse>(`/items.php?${qs.toString()}`)
      .then(setData)
      .catch(() => setData(null))
      .finally(() => setLoading(false));
  }, [slug, q, areaId, status]);

  const grouped = useMemo(() => {
    if (!data) return {} as Record<string, ServiceItem[]>;
    const g: Record<string, ServiceItem[]> = {};
    for (const it of data.items) {
      (g[it.neighborhood] ??= []).push(it);
    }
    return Object.fromEntries(Object.entries(g).sort(([a], [b]) => a.localeCompare(b, 'ar')));
  }, [data]);

  const updateParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value === '' || value === 'all') next.delete(key);
    else next.set(key, value);
    setParams(next);
  };

  const hasFilters = q !== '' || areaId !== 'all' || status !== 'all';

  return (
    <section className="section" style={{ paddingTop: 0 }}>
      <div className="page-head container">
        <h1>{data ? `${data.category.icon} ${data.category.name}` : ''}</h1>
        <p className="sub">{subtitle}</p>
      </div>

      <div className="container">
        <div className="filters-bar">
          <form onSubmit={(e) => e.preventDefault()}>
            <input
              type="text"
              className="search-input field"
              placeholder="ابحث بالاسم أو الحي..."
              value={q}
              onChange={(e) => updateParam('q', e.target.value)}
            />

            <select className="field" value={areaId} onChange={(e) => updateParam('area_id', e.target.value)}>
              <option value="all">كل المناطق</option>
              {areas.map((a) => (
                <option key={a.id} value={a.id}>{a.type} {a.name}</option>
              ))}
            </select>

            {(showOpenFilter || showDutyFilter) && (
              <select className="field" value={status} onChange={(e) => updateParam('status', e.target.value)}>
                <option value="all">الكل</option>
                {showOpenFilter && <option value="open">تعمل الآن</option>}
                {showDutyFilter && <option value="duty">المناوبة هذا الأسبوع</option>}
              </select>
            )}

            {hasFilters && (
              <button type="button" className="btn secondary" onClick={() => setParams({})}>
                إلغاء الفلاتر
              </button>
            )}
          </form>
        </div>

        {!loading && data && (
          <p className="result-count">{data.items.length} نتيجة من أصل {data.total} — بطاقات مجمّعة حسب الحي</p>
        )}

        {loading && <p className="result-count">جارٍ التحميل...</p>}

        {!loading && data && Object.keys(grouped).length === 0 && (
          <div className="empty-state"><p>لا توجد نتائج مطابقة لبحثك حالياً.</p></div>
        )}

        {!loading && Object.entries(grouped).map(([nb, items]) => (
          <div className="nb-group" key={nb}>
            <h2>{nb} <span style={{ color: 'var(--muted)', fontWeight: 600, fontSize: '.85rem' }}>({items.length})</span></h2>
            <div className="items-grid">
              {items.map((it) => (
                <div className="item-card" key={it.id}>
                  {showDutyFilter && it.on_duty && <span className="badge duty">مناوبة هذا الأسبوع</span>}
                  {showOpenFilter && (
                    <span className={`badge ${it.is_open ? 'open' : 'closed'}`}>{it.is_open ? 'تعمل الآن' : 'مغلقة حالياً'}</span>
                  )}
                  <h3>{it.name}</h3>
                  {it.specialty && <p className="meta">🩺 {it.specialty}</p>}
                  {it.fuel_types && <p className="meta">⛽ {it.fuel_types}</p>}
                  {it.route && <p className="meta">🛣️ {it.route}</p>}
                  {it.area_name && <p className="meta">{it.area_type} {it.area_name}</p>}
                  {it.notes && <p className="meta">{it.notes}</p>}
                  {it.phone && <a className="phone" href={`tel:${it.phone}`}>📞 {it.phone}</a>}
                </div>
              ))}
            </div>
          </div>
        ))}

        <div className="cta-add">
          {data?.category.name ?? 'الخدمة'} غير موجودة بالدليل؟ <Link to={`/add-request?cat=${slug}`}>أضفها الآن</Link>
        </div>
      </div>
    </section>
  );
}
