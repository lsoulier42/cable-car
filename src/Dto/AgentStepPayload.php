<?php

namespace App\Dto;

use App\Entity\AgentStep;

/**
 * Serializable view of one observable step of a run.
 */
final class AgentStepPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromStep(AgentStep $step): array
    {
        return [
            'id' => $step->getUuid()?->toRfc4122(),
            'sequence' => $step->getSequence(),
            'type' => $step->getType()->value,
            'toolName' => $step->getToolName(),
            'toolInput' => $step->getToolInput(),
            'resultSummary' => $step->getResultSummary(),
            'message' => $step->getMessage(),
            'success' => $step->isSuccess(),
            'durationMs' => $step->getDurationMs(),
            'createdAt' => $step->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
