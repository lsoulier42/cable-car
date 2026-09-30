<?php

namespace App\Dto;

use App\Entity\AgentRun;

/**
 * Serializable view of a run for the HTTP API.
 */
final class AgentRunPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromRun(AgentRun $run, bool $withSteps = false): array
    {
        $payload = [
            'id' => $run->getUuid()?->toRfc4122(),
            'workspace' => $run->getWorkspace(),
            'task' => $run->getTask(),
            'model' => $run->getModel(),
            'status' => $run->getStatus()->value,
            'statusLabel' => $run->getStatus()->label(),
            'stopReason' => $run->getStopReason()?->value,
            'stopReasonLabel' => $run->getStopReason()?->label(),
            'finalMessage' => $run->getFinalMessage(),
            'error' => $run->getError(),
            'iterationCount' => $run->getIterationCount(),
            'toolCallCount' => $run->getToolCallCount(),
            'createdAt' => $run->getCreatedAt()?->format(\DATE_ATOM),
            'startedAt' => $run->getStartedAt()?->format(\DATE_ATOM),
            'finishedAt' => $run->getFinishedAt()?->format(\DATE_ATOM),
            'durationSeconds' => $run->getDurationSeconds(),
            'cancellationRequested' => $run->isCancellationRequested(),
            'changedFiles' => $run->getChangedFiles(),
        ];

        if ($withSteps) {
            $payload['steps'] = array_map(
                static fn (\App\Entity\AgentStep $step): array => AgentStepPayload::fromStep($step),
                $run->getSteps()->toArray(),
            );
        }

        return $payload;
    }
}
