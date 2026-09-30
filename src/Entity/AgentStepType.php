<?php

namespace App\Entity;

/**
 * Kind of an observable step of a run. Only observable actions are stored:
 * no chain-of-thought ever reaches this table.
 */
enum AgentStepType: string
{
    case Model = 'model';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Final = 'final';
    case Error = 'error';
}
