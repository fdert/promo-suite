import { forwardRef } from 'react';

const variants = {
  primary: 'bg-accent text-white hover:bg-accent-dark focus-visible:ring-accent',
  secondary: 'bg-ink text-white hover:bg-black focus-visible:ring-ink',
  outline: 'border border-line bg-white text-ink hover:bg-paper focus-visible:ring-ink',
  ghost: 'text-ink hover:bg-paper focus-visible:ring-ink',
  danger: 'bg-danger text-white hover:bg-danger/90 focus-visible:ring-danger',
};

const sizes = {
  sm: 'h-8 px-3 text-sm',
  md: 'h-10 px-4 text-sm',
  lg: 'h-12 px-6 text-base',
};

const Button = forwardRef(function Button(
  { variant = 'primary', size = 'md', className = '', disabled, loading, children, ...props },
  ref
) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={`inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors
        focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2
        disabled:opacity-50 disabled:cursor-not-allowed
        ${variants[variant]} ${sizes[size]} ${className}`}
      {...props}
    >
      {loading && (
        <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
      )}
      {children}
    </button>
  );
});

export default Button;
