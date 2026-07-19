import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function PlatformRoute({ children }) {
  const { user, isPlatformAdmin } = useAuth();
  if (!user) return <Navigate to="/login" replace />;
  if (!isPlatformAdmin) return <Navigate to="/dashboard" replace />;
  return children;
}
