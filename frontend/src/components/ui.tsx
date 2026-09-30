import type {
  ButtonHTMLAttributes,
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react';
import { AlertCircle, CheckCircle2, Loader2 } from 'lucide-react';
import { cn } from '../lib/utils';

/* ---------- Button ---------- */

type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'dangerGhost';
type ButtonSize = 'sm' | 'md' | 'icon';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
}

const buttonVariants: Record<ButtonVariant, string> = {
  primary: 'bg-primary text-white hover:bg-primary-hover focus-visible:outline-primary shadow-soft',
  secondary:
    'bg-transparent text-ink ring-1 ring-inset ring-line-strong hover:bg-surface-3 focus-visible:outline-primary',
  danger: 'bg-danger text-white hover:bg-danger/90 focus-visible:outline-danger shadow-soft',
  ghost: 'text-muted hover:bg-primary/10 hover:text-primary focus-visible:outline-primary',
  dangerGhost: 'text-muted hover:bg-danger/15 hover:text-danger focus-visible:outline-danger',
};

const buttonSizes: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-xs',
  md: 'h-9 px-4 text-sm',
  icon: 'h-8 w-8 shrink-0 p-0',
};

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  className,
  children,
  disabled,
  ...props
}: ButtonProps) {
  return (
    <button
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-field font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        buttonVariants[variant],
        buttonSizes[size],
        className,
      )}
      disabled={disabled || loading}
      {...props}
    >
      {loading && <Loader2 className="h-4 w-4 animate-spin" aria-hidden />}
      {children}
    </button>
  );
}

/* ---------- Field + Input ---------- */

interface FieldProps {
  label: string;
  htmlFor: string;
  error?: string;
  hint?: string;
  children: ReactNode;
}

export function Field({ label, htmlFor, error, hint, children }: FieldProps) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={htmlFor} className="block text-sm font-medium text-muted">
        {label}
      </label>
      {children}
      {hint && !error && <p className="text-xs text-faint">{hint}</p>}
      {error && <p className="text-xs font-medium text-danger">{error}</p>}
    </div>
  );
}

interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  invalid?: boolean;
}

export function Input({ invalid = false, className, ...props }: InputProps) {
  return (
    <input
      className={cn(
        'block w-full rounded-field border bg-surface-2 px-3.5 py-2 text-sm text-ink transition placeholder:text-faint focus:outline-none',
        invalid
          ? 'border-danger/70 focus:border-danger focus:ring-2 focus:ring-danger/20'
          : 'border-line focus:border-primary/70 focus:ring-2 focus:ring-primary/20',
        className,
      )}
      {...props}
    />
  );
}

/* ---------- Select ---------- */

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  invalid?: boolean;
}

export function Select({ invalid = false, className, children, ...props }: SelectProps) {
  return (
    <select
      className={cn(
        'block w-full rounded-field border bg-surface-2 px-3.5 py-2 text-sm text-ink transition focus:outline-none',
        invalid
          ? 'border-danger/70 focus:border-danger focus:ring-2 focus:ring-danger/20'
          : 'border-line focus:border-primary/70 focus:ring-2 focus:ring-primary/20',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
}

/* ---------- Textarea ---------- */

interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean;
}

export function Textarea({ invalid = false, className, ...props }: TextareaProps) {
  return (
    <textarea
      className={cn(
        'block w-full rounded-field border bg-surface-2 px-3.5 py-2 text-sm text-ink transition placeholder:text-faint focus:outline-none',
        invalid
          ? 'border-danger/70 focus:border-danger focus:ring-2 focus:ring-danger/20'
          : 'border-line focus:border-primary/70 focus:ring-2 focus:ring-primary/20',
        className,
      )}
      {...props}
    />
  );
}

/* ---------- Card ---------- */

export function Card({ className, children }: { className?: string; children: ReactNode }) {
  return (
    <div className={cn('rounded-card border border-line bg-surface-2 shadow-card', className)}>{children}</div>
  );
}

/** Slightly contrasted card header (uppercase title). */
export function CardHeader({ title, aside }: { title: string; aside?: ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b border-line bg-surface-3/50 px-5 py-3.5">
      <h2 className="text-xs font-semibold uppercase tracking-widest text-muted">{title}</h2>
      {aside}
    </div>
  );
}

/* ---------- Alert ---------- */

type AlertKind = 'error' | 'success';

export function Alert({ kind, children }: { kind: AlertKind; children: ReactNode }) {
  const styles =
    kind === 'error'
      ? 'border-danger/30 bg-danger/10 text-danger'
      : 'border-success/30 bg-success/10 text-success';
  const Icon = kind === 'error' ? AlertCircle : CheckCircle2;

  return (
    <div className={cn('flex items-start gap-2.5 rounded-box border px-3.5 py-2.5 text-sm', styles)} role="alert">
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div>{children}</div>
    </div>
  );
}

/* ---------- Badge (pill) ---------- */

type BadgeTone = 'neutral' | 'primary' | 'accent' | 'success' | 'warning' | 'danger';

const badgeTones: Record<BadgeTone, string> = {
  neutral: 'bg-white/5 text-muted',
  primary: 'bg-primary/12 text-primary',
  accent: 'bg-accent/12 text-accent',
  success: 'bg-success/12 text-success',
  warning: 'bg-warning/12 text-warning',
  danger: 'bg-danger/12 text-danger',
};

export function Badge({ tone = 'neutral', children }: { tone?: BadgeTone; children: ReactNode }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium',
        badgeTones[tone],
      )}
    >
      {children}
    </span>
  );
}

/* ---------- Spinner / states ---------- */

export function Spinner({ label = 'Chargement…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted">
      <Loader2 className="h-5 w-5 animate-spin" aria-hidden />
      {label}
    </div>
  );
}

export function ErrorState({ message }: { message: string }) {
  return (
    <div className="py-8">
      <Alert kind="error">{message}</Alert>
    </div>
  );
}