import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './context/ToastContext';
import ProtectedRoute from './components/ProtectedRoute';
import Layout from './components/Layout';

import Login from './pages/Login';
import Signup from './pages/Signup';
import Dashboard from './pages/Dashboard';
import Customers from './pages/Customers';
import Orders from './pages/Orders';
import Payments from './pages/Payments';
import Expenses from './pages/Expenses';
import Employees from './pages/Employees';
import WhatsAppInbox from './pages/WhatsAppInbox';
import Installments from './pages/Installments';
import Reports from './pages/Reports';
import Evaluations from './pages/Evaluations';
import Settings from './pages/Settings';
import Pricing from './pages/Pricing';
import Checkout from './pages/Checkout';
import CheckoutComplete from './pages/CheckoutComplete';
import PlatformAdmin from './pages/PlatformAdmin';
import PlatformRoute from './components/PlatformRoute';
import NotFound from './pages/NotFound';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/signup" element={<Signup />} />
            <Route path="/pricing" element={<Pricing />} />

            <Route
              path="/billing/checkout"
              element={
                <ProtectedRoute>
                  <Checkout />
                </ProtectedRoute>
              }
            />
            <Route
              path="/billing/checkout/complete"
              element={
                <ProtectedRoute>
                  <CheckoutComplete />
                </ProtectedRoute>
              }
            />

            <Route
              element={
                <ProtectedRoute>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<Dashboard />} />
              <Route path="/customers" element={<Customers />} />
              <Route path="/orders" element={<Orders />} />
              <Route path="/payments" element={<Payments />} />
              <Route path="/installments" element={<Installments />} />
              <Route path="/expenses" element={<Expenses />} />
              <Route path="/employees" element={<Employees />} />
              <Route path="/whatsapp" element={<WhatsAppInbox />} />
              <Route path="/reports" element={<Reports />} />
              <Route path="/evaluations" element={<Evaluations />} />
              <Route path="/settings" element={<Settings />} />
              <Route
                path="/admin"
                element={
                  <PlatformRoute>
                    <PlatformAdmin />
                  </PlatformRoute>
                }
              />
            </Route>

            <Route path="*" element={<NotFound />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </AuthProvider>
  );
}
