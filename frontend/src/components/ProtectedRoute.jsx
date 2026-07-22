import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { PageLoading } from './ui/Surfaces';

export default function ProtectedRoute({ children }) {
  const { user, checkingSession } = useAuth();
  if (checkingSession) return <PageLoading />;
  if (!user) return <Navigate to="/login" replace />;
  return children;
}
