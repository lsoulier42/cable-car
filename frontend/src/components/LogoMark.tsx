import { useId } from 'react';

/** Brand mark: white sparkle on an indigo-violet gradient square (matches the favicon). */
export function LogoMark({ className }: { className?: string }) {
  const gradientId = useId();

  return (
    <svg viewBox="0 0 64 64" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#4f46e5" />
          <stop offset="1" stopColor="#7c3aed" />
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="15" fill={`url(#${gradientId})`} />
      <polygon points="32,12 38.5,25.5 52,32 38.5,38.5 32,52 25.5,38.5 12,32 25.5,25.5" fill="#ffffff" />
    </svg>
  );
}