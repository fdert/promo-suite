export function Label({ children, required }) {
  return (
    <label className="mb-1.5 block text-sm font-medium text-ink">
      {children}
      {required && <span className="text-danger"> *</span>}
    </label>
  );
}

const baseInput =
  'w-full rounded-lg border border-line bg-white px-3 h-10 text-sm text-ink placeholder:text-ink-faint ' +
  'focus:outline-none focus:ring-2 focus:ring-accent/40 focus:border-accent transition-colors';

export function Input({ className = '', ...props }) {
  return <input className={`${baseInput} ${className}`} {...props} />;
}

export function Textarea({ className = '', rows = 3, ...props }) {
  return <textarea rows={rows} className={`${baseInput} h-auto py-2 ${className}`} {...props} />;
}

export function Select({ className = '', children, ...props }) {
  return (
    <select className={`${baseInput} ${className}`} {...props}>
      {children}
    </select>
  );
}

export function FormRow({ label, required, error, children }) {
  return (
    <div>
      <Label required={required}>{label}</Label>
      {children}
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  );
}
