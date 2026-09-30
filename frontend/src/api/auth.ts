import { apiClient } from './client';
import type { LoginResponse, MeDto } from './types';

export interface RegisterInput {
  email: string;
  plainPassword: string;
  firstName?: string;
  lastName?: string;
}

export interface UpdateMeInput {
  email: string;
  firstName: string | null;
  lastName: string | null;
  plainPassword?: string;
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const { data } = await apiClient.post<LoginResponse>('/login', { email, password });
  return data;
}

export async function register(input: RegisterInput): Promise<MeDto> {
  const { data } = await apiClient.post<MeDto>('/register', input);
  return data;
}

export async function getMe(): Promise<MeDto> {
  const { data } = await apiClient.get<MeDto>('/me');
  return data;
}

export async function updateMe(input: UpdateMeInput): Promise<MeDto> {
  const { data } = await apiClient.patch<MeDto>('/me', input);
  return data;
}