export function Card({ className = '', hover = false, children }) {
  return (
    <div
      className={`rounded-2xl border border-line bg-white p-5 shadow-[0_1px_2px_rgba(21,22,28,0.04)] transition-all ${
        hover ? 'hover:-translate-y-0.5 hover:shadow-[0_12px_24px_-8px_rgba(21,22,28,0.12)]' : ''
      } ${className}`}
    >
      {children}
    </div>
  );
}

export function CardHeader({ title, subtitle, action }) {
  return (
    <div className="mb-5 flex items-start justify-between gap-3">
      <div>
        <h3 className="font-display text-base font-bold text-ink">{title}</h3>
        {subtitle && <p className="mt-1 text-sm leading-relaxed text-ink-soft">{subtitle}</p>}
      </div>
      {action}
    </div>
  );
}

const statusStyles = {
  neutral: 'bg-line/60 text-ink-soft',
  accent: 'bg-accent-light text-accent-dark',
  success: 'bg-success-light text-success',
  danger: 'bg-danger-light text-danger',
  warning: 'bg-warning-light text-warning',
};

const dotStyles = {
  neutral: 'bg-ink-faint',
  accent: 'bg-accent-dark',
  success: 'bg-success',
  danger: 'bg-danger',
  warning: 'bg-warning',
};

export function Badge({ tone = 'neutral', dot = false, children }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${statusStyles[tone]}`}>
      {dot && <span className={`h-1.5 w-1.5 rounded-full ${dotStyles[tone]}`} />}
      {children}
    </span>
  );
}

export function EmptyState({ icon: Icon, title, description, action }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-line py-16 text-center">
      {Icon && <Icon className="mb-3 h-10 w-10 text-ink-faint" strokeWidth={1.5} />}
      <p className="font-display font-bold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-ink-soft">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function Spinner({ className = '' }) {
  return <span className={`inline-block h-5 w-5 animate-spin rounded-full border-2 border-line border-t-accent ${className}`} />;
}

export function PageLoading() {
  return (
    <div className="flex h-64 items-center justify-center">
      <Spinner />
    </div>
  );
}
