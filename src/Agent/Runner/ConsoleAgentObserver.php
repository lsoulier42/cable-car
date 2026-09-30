<?php

namespace App\Agent\Runner;

use App\Agent\DTO\AgentToolCall;
use App\Agent\Tool\ToolResult;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the observable activity of a run: model messages meant for the user,
 * tool calls and their results. Hidden reasoning is never displayed because it
 * is never requested or stored.
 */
final class ConsoleAgentObserver implements AgentObserverInterface
{
    public function __construct(
        private readonly OutputInterface $output,
        private readonly CancellationToken $cancellation = new CancellationToken(),
    ) {
    }

    public function isCancelled(): bool
    {
        return $this->cancellation->isCancelled();
    }

    public function onRunStarted(AgentContext $context): void
    {
        $this->output->writeln(sprintf(
            '<fg=cyan>▸ workspace</> %s <fg=gray>·</> model <info>%s</info> <fg=gray>(%s)</>',
            $context->getWorkspace()->getId(),
            $context->getModel()->getName(),
            $context->getModel()->getModelId(),
        ));
        $this->output->writeln(sprintf('<fg=cyan>▸ task</> %s', $context->getTask()));
        $this->output->writeln('');
    }

    public function onModelMessage(string $text): void
    {
        foreach (explode("\n", trim($text)) as $line) {
            $this->output->writeln(sprintf('<fg=cyan>[agent]</> %s', $line));
        }
    }

    public function onToolCall(AgentToolCall $call): void
    {
        $this->output->writeln(sprintf(
            '<fg=yellow>[tool]</>  <options=bold>%s</> %s',
            $call->getName(),
            $call->getSummary(),
        ));
    }

    public function onToolResult(AgentToolCall $call, ToolResult $result, float $durationMs): void
    {
        if ($result->isSuccess()) {
            $summary = $this->summarize($result->getOutput());
            $this->output->writeln(sprintf(
                '        <fg=gray>→ %s (%d ms)</>',
                $summary,
                (int) round($durationMs),
            ));

            if ($this->output->isVerbose() && '' !== trim($result->getOutput())) {
                $this->output->writeln($this->indent($result->getOutput()));
            }

            return;
        }

        $this->output->writeln(sprintf('        <fg=red>! %s</>', $this->summarize($result->getError() ?? 'failure')));
        if ($this->output->isVerbose()) {
            $this->output->writeln($this->indent($result->getError() ?? ''));
        }
    }

    public function onRunFinished(AgentOutcome $outcome): void
    {
        $this->output->writeln('');

        $icon = $outcome->isSuccess() ? '<fg=green>✔</>' : '<fg=red>✘</>';
        $this->output->writeln(sprintf(
            '%s %s <fg=gray>·</> %d itérations <fg=gray>·</> %d appels d\'outils <fg=gray>·</> %.1f s',
            $icon,
            $outcome->getStopReason()->label(),
            $outcome->getIterations(),
            $outcome->getToolCalls(),
            $outcome->getDurationSeconds(),
        ));

        if (null !== $outcome->getError()) {
            $this->output->writeln(sprintf('<fg=red>%s</>', $outcome->getError()));
        }

        if (null !== $outcome->getFinalMessage()) {
            $this->output->writeln('');
            $this->output->writeln('<fg=cyan>Réponse finale</>');
            $this->output->writeln($outcome->getFinalMessage());
        }

        $changed = $outcome->getChangedFiles();
        if ([] !== $changed) {
            $this->output->writeln('');
            $this->output->writeln('<fg=cyan>Fichiers modifiés</>');
            foreach ($changed as $file) {
                $this->output->writeln(sprintf(
                    '  <options=bold>%s</> %s',
                    strtoupper(substr($file->getStatus(), 0, 1)),
                    $file->getPath(),
                ));
            }

            $this->output->writeln('');
            $this->output->writeln(
                '<fg=gray>Les changements ne sont ni commités ni poussés : à vous de les relire et de les valider.</>',
            );
        }
    }

    private function summarize(string $text): string
    {
        $text = trim($text);
        if ('' === $text) {
            return 'ok';
        }

        $lines = explode("\n", $text);
        $first = $lines[0];
        $summary = mb_strlen($first) > 110 ? mb_substr($first, 0, 110) . '…' : $first;

        if (\count($lines) > 1) {
            $summary .= sprintf(' (+%d lignes)', \count($lines) - 1);
        }

        return $summary;
    }

    private function indent(string $text): string
    {
        $lines = explode("\n", rtrim($text));
        $lines = \array_slice($lines, 0, 25);

        return implode("\n", array_map(static fn (string $line): string => '        <fg=gray>|</> ' . $line, $lines));
    }
}
