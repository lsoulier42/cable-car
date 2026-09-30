import { apiClient } from './client';
import type { UserDto, UsersCollection, UserPatchInput } from './types';

export interface UserListParams {
  page?: number;
  itemsPerPage?: number;
  email?: string;
  isActive?: boolean;
}

export async function getUsers(params: UserListParams = {}): Promise<UsersCollection> {
  const { data } = await apiClient.get<UsersCollection>('/users', { params });
  return data;
}

export async function patchUser(id: number, input: UserPatchInput): Promise<UserDto> {
  const { data } = await apiClient.patch<UserDto>(`/users/${id}`, input, {
    headers: { 'Content-Type': 'application/merge-patch+json' },
  });
  return data;
}

export async function deleteUser(id: number): Promise<void> {
  await apiClient.delete(`/users/${id}`);
}