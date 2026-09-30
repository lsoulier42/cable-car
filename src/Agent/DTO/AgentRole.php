<?php

namespace App\Agent\DTO;

/**
 * Role of an entry in the conversation sent to the model.
 */
enum AgentRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
