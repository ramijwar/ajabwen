import { useState } from 'react';
import { Link } from 'react-router-dom';

export default function Header() {
  const [open, setOpen] = useState(false);
  return (
    <header className="site-header">
      <div className="container header-inner">
        <Link to="/" className="brand">
          <span className="brand-icon">📍</span>
          <span>عَجَب وين في؟</span>
        </Link>
        <button className="nav-toggle" aria-label="القائمة" onClick={() => setOpen((o) => !o)}>☰</button>
        <nav className={`main-nav${open ? ' open' : ''}`} onClick={() => setOpen(false)}>
          <Link to="/#services">الخدمات</Link>
          <Link to="/about">من نحن</Link>
          <Link to="/terms">سياسة الاستخدام</Link>
        </nav>
      </div>
    </header>
  );
}
