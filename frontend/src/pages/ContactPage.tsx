import { Link } from 'react-router-dom';

export default function ContactPage() {
  return (
    <>
      <section className="page-head"><div className="container"><h1>تواصل معنا</h1></div></section>
      <section className="section" style={{ paddingTop: 0 }}>
        <div className="container">
          <div className="form-card" style={{ textAlign: 'right', maxWidth: 720 }}>
            <p>لأصحاب الخدمات الراغبين بإضافة أو تحديث بياناتهم، يمكنكم استخدام <Link to="/add-request">نموذج إضافة خدمة</Link> مباشرة، وسيقوم فريقنا بمراجعته في أقرب وقت.</p>
            <p>لأي استفسار آخر يمكنكم مراسلتنا عبر البريد الإلكتروني: <b>info@ajabwen.example</b></p>
          </div>
        </div>
      </section>
    </>
  );
}
