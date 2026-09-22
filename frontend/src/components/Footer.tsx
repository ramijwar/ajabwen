import { Link } from 'react-router-dom';

export default function Footer() {
  return (
    <footer className="site-footer">
      <div className="container footer-inner">
        <div className="footer-brand">
          <div className="brand"><span className="brand-icon">📍</span><span>عَجَب وين في؟</span></div>
          <p>مبادرة أهلية مستقلة لمتابعة حالة الخدمات المحلية — كازيات وصيدليات وأطباء ونقل. هدفها تسهيل حياة الناس اليومية، وليست تابعة لأي جهة حكومية أو رسمية.</p>
        </div>
        <div className="footer-col">
          <h4>روابط سريعة</h4>
          <ul>
            <li><Link to="/">الرئيسية</Link></li>
            <li><Link to="/stations">كازيات</Link></li>
            <li><Link to="/pharmacies">الصيدليات المناوبة</Link></li>
            <li><Link to="/doctors">طبيب مختص</Link></li>
            <li><Link to="/transport">سرافيس وباصات</Link></li>
          </ul>
        </div>
        <div className="footer-col">
          <h4>عن المنصة</h4>
          <ul>
            <li><Link to="/about">من نحن</Link></li>
            <li><Link to="/terms">سياسة الاستخدام</Link></li>
            <li><Link to="/contact">تواصل معنا</Link></li>
          </ul>
        </div>
      </div>
      <div className="container">
        <p className="copyright">© {new Date().getFullYear()} عَجَب وين في؟ — منصة أهلية غير حكومية وغير رسمية. البيانات المعروضة تعتمد على تحديثات أصحاب الخدمات، فقد لا تعكس الوضع الفعلي لحظة زيارتك.</p>
      </div>
    </footer>
  );
}
