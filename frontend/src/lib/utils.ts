/** Utility helpers shared across the app. */

/** Joins class names, ignoring falsy values. */
export function cn(...classes: Array<string | false | null | undefined>): string {
  return classes.filter(Boolean).join(' ');
}

/** Formats an ISO date (UTC from the API) to a French locale date. */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—';
  }
  return new Date(iso).toLocaleDateString('fr-FR', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

/** Decodes the payload of a Lexik JWT (base64url). Returns null when malformed. */
export interface JwtPayload {
  email: string;
  roles: string[];
  exp: number;
}

export function parseJwt(token: string): JwtPayload | null {
  try {
    const payload = JSON.parse(atob(token.split('.')[1])) as Partial<JwtPayload>;
    return {
      email: payload.email ?? '',
      roles: payload.roles ?? [],
      exp: payload.exp ?? 0,
    };
  } catch {
    return null;
  }
}

/** Extracts the first violation message for a given field from an API 422 body. */
export function violationMessage(
  data: { violations?: Array<{ propertyPath: string; message: string }> } | undefined,
  field: string,
): string | undefined {
  return data?.violations?.find((v) => v.propertyPath === field)?.message;
}