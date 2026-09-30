<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentMessage;
use App\Agent\DTO\AgentToolCall;
use App\Agent\DTO\ChangedFile;
use App\Agent\Exception\ModelException;
use App\Agent\Exception\RunAbortedException;
use App\Agent\Exception\RunStoppedException;
use App\Agent\Exception\WorkspaceException;
use App\Agent\Git\GitInspector;
use App\Agent\Model\CodingModelRegistry;
use App\Agent\Tool\ToolContext;
use App\Agent\Tool\ToolRegistry;
use App\Agent\Tool\ToolResult;
use Psr\Log\LoggerInterface;

/**
 * The Cable Car loop.
 *
 * Cable Car owns the loop: it decides how long it runs, which tools exist,
 * what results are observed and when to stop. Symfony AI only turns messages
 * into model responses.
 *
 *   model turn -> tool calls -> tool results -> model turn -> … -> final answer
 */
final class AgentRunner
{
    public function __construct(
        private readonly CodingModelRegistry $models,
        private readonly ToolRegistry $tools,
        private readonly AgentLimits $defaultLimits,
        private readonly SystemPromptBuilder $prompts,
        private readonly GitInspector $git,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(
        AgentRunRequest $request,
        AgentObserverInterface $observer = new NullAgentObserver(),
    ): AgentOutcome {
        $startedAt = microtime(true);
        $model = $this->models->get($request->getModel());
        $limits = $request->getLimits() ?? $this->defaultLimits;

        $context = new AgentContext(
            $request->getWorkspace(),
            $request->getTask(),
            $model,
            $limits,
            $request->getRunId(),
        );

        $context->addMessage(AgentMessage::system($this->prompts->build($context)));
        $context->addMessage(AgentMessage::user($request->getTask()));

        $observer->onRunStarted($context);

        $this->logger->info('Agent run started', [
            'run' => $request->getRunId(),
            'workspace' => $request->getWorkspace()->getId(),
            'model' => $model->getName(),
            'tools' => $this->tools->getNames(),
        ]);

        $stopReason = StopReason::Completed;
        $finalMessage = null;
        $error = null;

        try {
            $finalMessage = $this->loop($context, $observer);
        } catch (RunStoppedException $exception) {
            $stopReason = $exception->getStopReason();
            $error = $exception->getMessage();
        } catch (RunAbortedException $exception) {
            $stopReason = $exception->getStopReason();
            $error = $exception->getMessage();
        } catch (ModelException $exception) {
            $stopReason = StopReason::ModelError;
            $error = $exception->getMessage();
        } catch (WorkspaceException $exception) {
            $stopReason = StopReason::UnrecoverableToolError;
            $error = $exception->getMessage();
        }

        if (null !== $error) {
            $this->logger->warning('Agent run stopped', [
                'run' => $request->getRunId(),
                'stop_reason' => $stopReason->value,
                'error' => $error,
            ]);
        }

        $outcome = new AgentOutcome(
            stopReason: $stopReason,
            finalMessage: $finalMessage,
            error: $error,
            iterations: $context->getIteration(),
            toolCalls: $context->getToolCallCount(),
            durationSeconds: microtime(true) - $startedAt,
            changedFiles: $this->safeChangedFiles($request, $context),
            modelName: $model->getName(),
            workspaceId: $request->getWorkspace()->getId(),
        );

        $observer->onRunFinished($outcome);

        $this->logger->info('Agent run finished', [
            'run' => $request->getRunId(),
            'stop_reason' => $outcome->getStopReason()->value,
            'iterations' => $outcome->getIterations(),
            'tool_calls' => $outcome->getToolCalls(),
            'duration' => round($outcome->getDurationSeconds(), 2),
        ]);

        return $outcome;
    }

    /**
     * @return string the final answer when the model completed the task
     */
    private function loop(AgentContext $context, AgentObserverInterface $observer): string
    {
        $limits = $context->getLimits();

        $context->assertUsable();

        for ($iteration = 0; $iteration < $limits->getMaxIterations(); ++$iteration) {
            if ($observer->isCancelled() || $context->isCancelled()) {
                throw new RunStoppedException(StopReason::UserCancelled, 'Run cancelled by the user.');
            }

            if ($context->isTimedOut()) {
                throw new RunStoppedException(
                    StopReason::Timeout,
                    sprintf('Run exceeded %d seconds.', $limits->getMaxRunSeconds()),
                );
            }

            $context->incrementIteration();
            $turn = $context->getModel()->respond($context);

            $text = null === $turn->getText() ? null : trim($turn->getText());
            if (null !== $text && '' !== $text) {
                $observer->onModelMessage($text);
            }

            $context->addMessage(AgentMessage::assistant($text, $turn->getToolCalls()));

            if (!$turn->hasToolCalls()) {
                if (null !== $text && '' !== $text) {
                    return $text;
                }

                throw new RunStoppedException(
                    StopReason::ModelError,
                    'The model returned an empty response without tool calls.',
                );
            }

            [$toolCalls, $excess] = $this->limitToolCalls($turn->getToolCalls(), $limits->getMaxToolCallsPerTurn());

            foreach ($toolCalls as $call) {
                if ($context->getToolCallCount() >= $limits->getMaxToolCalls()) {
                    throw new RunStoppedException(
                        StopReason::ToolCallLimit,
                        sprintf('Run exceeded %d tool calls.', $limits->getMaxToolCalls()),
                    );
                }

                $context->addToolCalls(1);
                $this->executeToolCall($call, $context, $observer);
            }

            foreach ($excess as $call) {
                $context->addMessage(AgentMessage::toolResult(
                    $call->getId(),
                    $call->getName(),
                    sprintf(
                        'ERROR: too many tool calls in a single turn (limit %d). Call them in smaller batches.',
                        $limits->getMaxToolCallsPerTurn(),
                    ),
                    false,
                ));
            }
        }

        throw new RunStoppedException(
            StopReason::IterationLimit,
            sprintf('Run reached the %d iterations limit.', $limits->getMaxIterations()),
        );
    }

    private function executeToolCall(
        AgentToolCall $call,
        AgentContext $context,
        AgentObserverInterface $observer,
    ): void {
        $observer->onToolCall($call);
        $limits = $context->getLimits();
        $startedAt = microtime(true);

        if (!$this->tools->has($call->getName())) {
            $result = ToolResult::failure(sprintf(
                'Unknown tool "%s". Available tools: %s.',
                $call->getName(),
                implode(', ', $this->tools->getNames()),
            ));
        } else {
            try {
                $result = $this->tools
                    ->get($call->getName())
                    ->execute($call->getArguments(), new ToolContext(
                        $context->getWorkspace(),
                        $limits,
                        $context->getRunId(),
                    ));
            } catch (\Throwable $exception) {
                throw new RunAbortedException(
                    StopReason::UnrecoverableToolError,
                    sprintf('Tool "%s" crashed: %s', $call->getName(), $exception->getMessage()),
                    $exception,
                );
            }
        }

        $durationMs = (microtime(true) - $startedAt) * 1000;
        $content = $this->capForModel($result->toModelContent(), $limits->getMaxToolOutputBytes());

        $context->addMessage(AgentMessage::toolResult(
            $call->getId(),
            $call->getName(),
            $content,
            $result->isSuccess(),
        ));

        $observer->onToolResult($call, $result, $durationMs);
    }

    /**
     * @param list<AgentToolCall> $calls
     *
     * @return array{0: list<AgentToolCall>, 1: list<AgentToolCall>}
     */
    private function limitToolCalls(array $calls, int $maxPerTurn): array
    {
        if (\count($calls) <= $maxPerTurn) {
            return [$calls, []];
        }

        return [\array_slice($calls, 0, $maxPerTurn), \array_slice($calls, $maxPerTurn)];
    }

    private function capForModel(string $content, int $maxBytes): string
    {
        if (\strlen($content) <= $maxBytes) {
            return $content;
        }

        return substr($content, 0, $maxBytes) . sprintf("\n… [output truncated: %d bytes]", \strlen($content));
    }

    /**
     * @return list<ChangedFile>
     */
    private function safeChangedFiles(AgentRunRequest $request, AgentContext $context): array
    {
        try {
            if (!$request->getWorkspace()->exists()) {
                return [];
            }

            return $this->git->changedFiles($request->getWorkspace());
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to compute the changed files.', ['error' => $exception->getMessage()]);

            return [];
        }
    }
}
