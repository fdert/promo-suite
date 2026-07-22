import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { PageLoading } from './ui/Surfaces';

export default function PlatformRoute({ children }) {
  const { user, isPlatformAdmin, checkingSession } = useAuth();
  if (checkingSession) return <PageLoading />;
  if (!user) return <Navigate to="/login" replace />;
  if (!isPlatformAdmin) return <Navigate to="/dashboard" replace />;
  return children;
}
