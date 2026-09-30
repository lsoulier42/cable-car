import { apiClient } from './client';

/** Types mirroring the /api/agent/* endpoints. */

export type AgentRunStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled' | 'limit_reached';

export type AgentStepType = 'model' | 'tool_call' | 'tool_result' | 'final' | 'error';

export interface AgentWorkspace {
  id: string;
  name: string;
}

export interface AgentModel {
  name: string;
  label: string;
  model: string;
  default: boolean;
}

export interface AgentChangedFile {
  path: string;
  status: string;
  diff?: string | null;
}

export interface AgentStep {
  id: string;
  sequence: number;
  type: AgentStepType;
  toolName: string | null;
  toolInput: Record<string, unknown> | null;
  resultSummary: string | null;
  message: string | null;
  success: boolean;
  durationMs: number | null;
  createdAt: string | null;
}

export interface AgentRun {
  id: string;
  workspace: string;
  task: string;
  model: string | null;
  status: AgentRunStatus;
  statusLabel: string;
  stopReason: string | null;
  stopReasonLabel: string | null;
  finalMessage: string | null;
  error: string | null;
  iterationCount: number;
  toolCallCount: number;
  createdAt: string | null;
  startedAt: string | null;
  finishedAt: string | null;
  durationSeconds: number | null;
  cancellationRequested: boolean;
  changedFiles: AgentChangedFile[];
  steps?: AgentStep[];
}

export interface AgentCollection<T> {
  member: T[];
  totalItems: number;
}

export interface CreateRunInput {
  workspace: string;
  task: string;
  model?: string | null;
}

export async function getWorkspaces(): Promise<AgentCollection<AgentWorkspace>> {
  const { data } = await apiClient.get<AgentCollection<AgentWorkspace>>('/agent/workspaces');
  return data;
}

export async function getModels(): Promise<AgentCollection<AgentModel> & { default: string }> {
  const { data } = await apiClient.get<AgentCollection<AgentModel> & { default: string }>('/agent/models');
  return data;
}

export async function createRun(input: CreateRunInput): Promise<AgentRun> {
  const { data } = await apiClient.post<AgentRun>('/agent/runs', input);
  return data;
}

export async function getRuns(limit = 50): Promise<AgentCollection<AgentRun>> {
  const { data } = await apiClient.get<AgentCollection<AgentRun>>('/agent/runs', { params: { limit } });
  return data;
}

export async function getRun(uuid: string): Promise<AgentRun> {
  const { data } = await apiClient.get<AgentRun>(`/agent/runs/${uuid}`);
  return data;
}

export async function getRunSteps(uuid: string): Promise<AgentCollection<AgentStep>> {
  const { data } = await apiClient.get<AgentCollection<AgentStep>>(`/agent/runs/${uuid}/steps`);
  return data;
}

export async function getRunChanges(uuid: string): Promise<AgentCollection<AgentChangedFile>> {
  const { data } = await apiClient.get<AgentCollection<AgentChangedFile>>(`/agent/runs/${uuid}/changes`);
  return data;
}

export async function cancelRun(uuid: string): Promise<AgentRun> {
  const { data } = await apiClient.post<AgentRun>(`/agent/runs/${uuid}/cancel`);
  return data;
}

export function isRunActive(status: AgentRunStatus): boolean {
  return status === 'pending' || status === 'running';
}
