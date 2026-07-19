import { Link } from 'react-router-dom';
import Button from '../components/ui/Button';

export default function NotFound() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center text-center">
      <p className="font-display text-6xl font-extrabold text-accent">404</p>
      <p className="mt-2 text-ink-soft">الصفحة التي تبحث عنها غير موجودة</p>
      <Link to="/" className="mt-6">
        <Button variant="outline">العودة للرئيسية</Button>
      </Link>
    </div>
  );
}
