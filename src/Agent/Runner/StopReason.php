<?php

namespace App\Agent\Runner;

/**
 * Why a run stopped. Persisted on the run so the UI can explain the outcome.
 */
enum StopReason: string
{
    case Completed = 'completed';
    case UserCancelled = 'user_cancelled';
    case IterationLimit = 'iteration_limit';
    case ToolCallLimit = 'tool_call_limit';
    case Timeout = 'timeout';
    case ModelError = 'model_error';
    case UnrecoverableToolError = 'unrecoverable_tool_error';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Tâche terminée',
            self::UserCancelled => 'Run annulé par l\'utilisateur',
            self::IterationLimit => 'Limite d\'itérations atteinte',
            self::ToolCallLimit => 'Limite d\'appels d\'outils atteinte',
            self::Timeout => 'Durée maximale du run dépassée',
            self::ModelError => 'Erreur du modèle',
            self::UnrecoverableToolError => 'Erreur d\'outil non récupérable',
        };
    }
}
