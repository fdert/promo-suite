// Thin client for the existing PHP backend (api/index.php).
// Mirrors the exact contract implemented server-side: a single endpoint with
// a `service` query param (db | auth | functions | storage) and an `action`
// field selecting the operation. See MULTI_TENANT_GUIDE.md / SECURITY_REPORT.md
// in the backend project for the full contract this was built against.

const API_BASE = '/api/index.php';

class ApiError extends Error {
  constructor(message, code, status) {
    super(message || 'حدث خطأ غير متوقع');
    this.code = code;
    this.status = status;
  }
}

async function request(service, body, { method = 'POST', query = '' } = {}) {
  const url = `${API_BASE}?service=${encodeURIComponent(service)}${query}`;
  const res = await fetch(url, {
    method,
    credentials: 'include', // send the PHP session cookie
    headers: { 'Content-Type': 'application/json' },
    body: method === 'POST' ? JSON.stringify(body || {}) : undefined,
  });

  let json;
  try {
    json = await res.json();
  } catch {
    throw new ApiError('استجابة غير صالحة من الخادم', 'bad_response', res.status);
  }

  if (!res.ok || json?.error) {
    const err = json?.error || {};
    if (res.status === 401) {
      // Session expired/invalid server-side (or tenant suspended - see 402
      // handling in AuthContext). Clear the locally cached profile and send
      // the person back to login rather than showing a confusing error.
      try { localStorage.removeItem('promo_suite_user'); } catch { /* ignore */ }
      if (!location.pathname.startsWith('/login')) {
        location.href = '/login';
      }
    }
    throw new ApiError(err.message, err.code, res.status);
  }
  return json.data;
}

// ---------------------------------------------------------------------------
// Generic table CRUD (service=db) — mirrors the backend's Supabase-like flavor
// ---------------------------------------------------------------------------
export const db = {
  /**
   * @param {string} table
   * @param {object} opts - { columns, where, filters, order, orderBy }
   *   filters: [{ column, op: 'eq'|'neq'|'gt'|'gte'|'lt'|'lte'|'like'|'ilike'|'in'|'between', value }]
   *   order: [{ column, direction: 'asc'|'desc' }]
   */
  select(table, opts = {}) {
    return request('db', { action: 'select', table, ...opts });
  },
  insert(table, data) {
    return request('db', { action: 'insert', table, data });
  },
  update(table, values, where) {
    return request('db', { action: 'update', table, values, where });
  },
  remove(table, where) {
    return request('db', { action: 'delete', table, where });
  },
  rpc(fn, params = {}) {
    return request('db', { action: 'rpc', fn, ...params });
  },
};

// ---------------------------------------------------------------------------
// Auth (service=auth)
// ---------------------------------------------------------------------------
export const auth = {
  signIn(email, password) {
    return request('auth', { action: 'signin', email, password });
  },
  signUp(email, password, metadata = {}) {
    return request('auth', { action: 'signup', email, password, metadata });
  },
  signOut() {
    return request('auth', { action: 'signout' });
  },
};

// ---------------------------------------------------------------------------
// Named backend operations (service=functions)
// ---------------------------------------------------------------------------
export const fn = {
  dashboardStats() {
    return request('functions', { action: 'dashboard-stats' });
  },
  dailyFinancialReport(test = false) {
    return request('functions', { action: 'daily-financial-report', test });
  },
  salaryReport(params = {}) {
    return request('functions', { action: 'salary-report', ...params });
  },
  paySalary(params) {
    return request('functions', { action: 'pay-salary', ...params });
  },
  searchOrdersForInstallment(q, opts = {}) {
    return request('functions', { action: 'search-orders-for-installment', q, ...opts });
  },
  call(action, params = {}) {
    return request('functions', { action, ...params });
  },
};

// ---------------------------------------------------------------------------
// Billing / subscription (service=functions, billing-* actions)
// ---------------------------------------------------------------------------
export const billing = {
  plans() {
    return request('functions', { action: 'billing-plans' });
  },
  status() {
    return request('functions', { action: 'billing-status' });
  },
  createPayment(planId) {
    return request('functions', { action: 'billing-create-payment', plan_id: planId });
  },
  confirmPayment(subscriptionId, paymentId) {
    return request('functions', { action: 'billing-confirm-payment', subscription_id: subscriptionId, payment_id: paymentId });
  },
};

// ---------------------------------------------------------------------------
// Platform admin (SaaS operator) — service=functions, platform-* actions.
// Tenant/plan CRUD itself goes through the regular `db` client above, since a
// platform_admin already has unrestricted access to those tables server-side.
// ---------------------------------------------------------------------------
export const platform = {
  stats() {
    return request('functions', { action: 'platform-stats' });
  },
  setTenantStatus(tenantId, status) {
    return request('functions', { action: 'platform-tenant-set-status', tenant_id: tenantId, status });
  },
};

// ---------------------------------------------------------------------------
// Editable homepage content (service=functions, platform-content-* actions)
// ---------------------------------------------------------------------------
export const platformContent = {
  get() {
    return request('functions', { action: 'platform-content-get' });
  },
  save(content) {
    return request('functions', { action: 'platform-content-save', content });
  },
};

// ---------------------------------------------------------------------------
// File upload (service=storage) — multipart, not JSON
// ---------------------------------------------------------------------------
export async function uploadFile(bucket, path, file) {
  const form = new FormData();
  form.append('bucket', bucket);
  form.append('path', path);
  form.append('file', file);
  const res = await fetch(`${API_BASE}?service=storage`, {
    method: 'POST',
    credentials: 'include',
    body: form,
  });
  const json = await res.json();
  if (!res.ok || json?.error) {
    throw new ApiError(json?.error?.message, json?.error?.code, res.status);
  }
  return json.data;
}

export { ApiError };
