import { useState } from 'react';
import { api } from '../api/client';

interface WipeOption { target: 'items' | 'requests' | 'areas' | 'all'; title: string; description: string }

const OPTIONS: WipeOption[] = [
  { target: 'items', title: 'مسح جميع الخدمات', description: 'يحذف كل السجلات في الصيدليات والأطباء والكازيات والنقل نهائياً.' },
  { target: 'requests', title: 'مسح جميع طلبات المستخدمين', description: 'يحذف كل طلبات "إضافة خدمة" المرسلة من الموقع العام (المعلّقة والمقبولة والمرفوضة).' },
  { target: 'areas', title: 'مسح جميع المناطق', description: 'يحذف كل القرى والبلدات والمدن المُدارة، وتُصبح الخدمات المرتبطة بها بلا منطقة محددة.' },
  { target: 'all', title: 'إعادة تعيين قاعدة البيانات بالكامل', description: 'يحذف كل الخدمات والطلبات والمناطق دفعة واحدة. حساب المدير والتصنيفات الأساسية تبقى كما هي.' },
];

export default function AdminWipe() {
  const [busy, setBusy] = useState<string | null>(null);
  const [result, setResult] = useState('');
  const [error, setError] = useState('');

  const runWipe = async (target: WipeOption['target'], title: string) => {
    const confirmText = target === 'all'
      ? 'سيتم حذف كل الخدمات والطلبات والمناطق نهائياً ولا يمكن التراجع. اكتب متأكداً بالضغط على "موافق" للمتابعة.'
      : `تأكيد: "${title}"؟ هذا الإجراء لا يمكن التراجع عنه.`;
    if (!confirm(confirmText)) return;

    setBusy(target);
    setError('');
    setResult('');
    try {
      const res = await api.post<{ success: boolean; result: Record<string, number> }>('/admin/wipe.php', { target, confirm: 'wipe' }, true);
      const parts = Object.entries(res.result).map(([k, v]) => `${v} سجل من ${k}`);
      setResult(`تم بنجاح: ${parts.join('، ')}.`);
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء المسح.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <div className="admin-topbar"><h1>🧹 مسح بيانات القاعدة</h1></div>

      <p style={{ color: 'var(--muted)', maxWidth: 640 }}>
        استخدم هذه الصفحة لمسح البيانات المخزّنة في القاعدة نهائياً — سواء كانت بيانات تجريبية أدخلتها للتجربة أو بيانات حقيقية تريد البدء من جديد بدونها.
        كل إجراء هنا <b>لا يمكن التراجع عنه</b>.
      </p>

      {result && <div className="alert success">{result}</div>}
      {error && <div className="alert error">{error}</div>}

      {OPTIONS.map((opt) => (
        <div className="danger-zone" key={opt.target}>
          <h3>{opt.title}</h3>
          <p style={{ color: 'var(--muted)', fontSize: '.88rem' }}>{opt.description}</p>
          <button className="btn danger" disabled={busy !== null} onClick={() => runWipe(opt.target, opt.title)}>
            {busy === opt.target ? 'جارٍ التنفيذ...' : opt.title}
          </button>
        </div>
      ))}
    </>
  );
}
