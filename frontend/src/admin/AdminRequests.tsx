import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api } from '../api/client';
import { ServiceRequest } from '../types';

export default function AdminRequests() {
  const [params, setParams] = useSearchParams();
  const status = params.get('status') ?? 'pending';
  const [requests, setRequests] = useState<ServiceRequest[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(() => {
    setLoading(true);
    api.get<ServiceRequest[]>(`/admin/requests.php?status=${status}`, true)
      .then(setRequests)
      .catch(() => setRequests([]))
      .finally(() => setLoading(false));
  }, [status]);

  useEffect(() => { load(); }, [load]);

  const doAction = async (action: string, id: number) => {
    if (action === 'delete' && !confirm('حذف هذا الطلب نهائياً؟')) return;
    await api.post('/admin/requests.php', { action, id }, true);
    load();
  };

  const statusLabel: Record<string, { label: string; cls: string }> = {
    pending: { label: 'بانتظار المراجعة', cls: '' },
    approved: { label: 'مقبولة', cls: 'open' },
    rejected: { label: 'مرفوضة', cls: 'closed' },
  };

  return (
    <>
      <div className="admin-topbar"><h1>📥 طلبات إضافة خدمات</h1></div>

      <div className="chip-group" style={{ marginBottom: 16 }}>
        {(['pending', 'approved', 'rejected', 'all'] as const).map((s) => (
          <button
            key={s}
            className={`chip${status === s ? ' active' : ''}`}
            onClick={() => setParams(s === 'pending' ? {} : { status: s })}
          >
            {s === 'pending' ? 'قيد الانتظار' : s === 'approved' ? 'مقبولة' : s === 'rejected' ? 'مرفوضة' : 'الكل'}
          </button>
        ))}
      </div>

      <div className="table-wrap">
        <table className="admin-table">
          <thead>
            <tr><th>التصنيف</th><th>الاسم</th><th>الحي</th><th>الهاتف</th><th>ملاحظات</th><th>الحالة</th><th>تاريخ الإرسال</th><th>إجراءات</th></tr>
          </thead>
          <tbody>
            {!loading && requests.length === 0 && (
              <tr><td colSpan={8} style={{ textAlign: 'center', color: 'var(--muted)' }}>لا توجد طلبات.</td></tr>
            )}
            {requests.map((r) => {
              const s = statusLabel[r.status] ?? { label: r.status, cls: '' };
              return (
                <tr key={r.id}>
                  <td>{r.category_icon} {r.category_name}</td>
                  <td>{r.name}</td>
                  <td>{r.neighborhood}</td>
                  <td>{r.phone || '—'}</td>
                  <td>{r.notes || '—'}</td>
                  <td>
                    <span className="badge" style={s.cls === '' ? { background: '#fff4de', color: 'var(--warn)' } : undefined}>{s.label}</span>
                  </td>
                  <td>{r.created_at}</td>
                  <td className="actions-cell">
                    {r.status === 'pending' && (
                      <>
                        <button className="btn small" onClick={() => doAction('approve', r.id)}>قبول ونشر</button>
                        <button className="btn small secondary" onClick={() => doAction('reject', r.id)}>رفض</button>
                      </>
                    )}
                    <button className="btn small danger" onClick={() => doAction('delete', r.id)}>حذف</button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </>
  );
}
