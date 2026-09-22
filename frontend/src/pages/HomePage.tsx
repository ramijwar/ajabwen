import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { Category } from '../types';

export default function HomePage() {
  const [cats, setCats] = useState<Category[]>([]);

  useEffect(() => {
    api.get<Category[]>('/categories.php').then(setCats).catch(() => setCats([]));
  }, []);

  const countOf = (slug: string) => cats.find((c) => c.slug === slug)?.count ?? 0;

  return (
    <>
      <section className="hero">
        <div className="container">
          <h1>عَجَب وين في؟</h1>
          <p>حالة عدد من الخدمات اليومية لحظةً بلحظة</p>
          <div className="stats-row">
            <span className="stat-pill"><b>{countOf('stations')}</b> كازية</span>
            <span className="stat-pill"><b>{countOf('pharmacies')}</b> صيدلية</span>
            <span className="stat-pill"><b>{countOf('doctors')}</b> طبيب</span>
            <span className="stat-pill"><b>{countOf('transport')}</b> خط نقل</span>
          </div>
        </div>
      </section>

      <section className="section" id="services">
        <div className="container">
          <h2 className="section-title">خدمات عامة</h2>
          <div className="services-grid">
            <Link className="service-card" to="/stations">
              <span className="icon">⛽</span>
              <h3>كازيات</h3>
              <p>حالة محطات الوقود لحظةً بلحظة</p>
            </Link>
            <Link className="service-card" to="/pharmacies">
              <span className="icon">💊</span>
              <h3>صيدليات مناوبة</h3>
              <p>حالة الصيدليات وتحديثات المناوبة</p>
            </Link>
            <Link className="service-card" to="/doctors">
              <span className="icon">🩺</span>
              <h3>طبيب مختص</h3>
              <p>أطباء المدينة واختصاصاتهم ومواعيد عياداتهم</p>
            </Link>
            <Link className="service-card" to="/transport">
              <span className="icon">🚌</span>
              <h3>سرافيس وباصات</h3>
              <p>خطوط النقل الداخلي في المدينة</p>
            </Link>
          </div>
        </div>
      </section>
    </>
  );
}
