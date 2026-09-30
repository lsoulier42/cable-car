/** API data types mirroring the Symfony (API Platform + custom controllers) responses. */

export interface UserDto {
  '@id': string;
  '@type': string;
  id: number;
  uuid: string;
  email: string;
  firstName: string | null;
  lastName: string | null;
  roles: string[];
  isActive: boolean;
}

export interface UsersCollection {
  '@context': string;
  '@id': string;
  '@type': string;
  totalItems: number;
  member: UserDto[];
  view?: {
    '@id': string;
    '@type': string;
    first: string;
    last: string;
    next?: string;
  };
}

export interface MeDto {
  email: string;
  firstName: string | null;
  lastName: string | null;
  roles: string[];
  uuid: string;
  isActive: boolean;
}

export interface LoginResponse {
  token: string;
}

export interface Violation {
  propertyPath: string;
  message: string;
}

export interface ApiErrorBody {
  message?: string;
  violations?: Violation[];
  code?: number;
}

export type UserPatchInput = Partial<Pick<UserDto, 'isActive' | 'roles' | 'email' | 'firstName' | 'lastName'>>;