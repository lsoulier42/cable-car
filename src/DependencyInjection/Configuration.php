<?php

namespace App\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for the `cable_car` extension.
 *
 * Everything the harness needs to be safe is configurable here rather than
 * hard-coded in the tools: workspaces root, models, limits and execution policy.
 */
final class Configuration implements ConfigurationInterface
{
    /**
     * Directories the filesystem tools never recurse into (relative to a workspace root).
     *
     * @var list<string>
     */
    public const DEFAULT_IGNORED_DIRECTORIES = [
        '.git',
        '.idea',
        '.vscode',
        'vendor',
        'node_modules',
        'var/cache',
        'var/log',
        'public/build',
        'frontend/dist',
        'frontend/node_modules',
    ];

    /**
     * Paths (relative to a workspace root) that tools may never read or write.
     *
     * Patterns are matched with fnmatch() against the relative path and the
     * basename, so `*.pem` also protects nested files.
     *
     * @var list<string>
     */
    public const DEFAULT_DENIED_PATHS = [
        '.env',
        '.env.*',
        '*.pem',
        '*.key',
        '*.p12',
        '*.pfx',
        '*.jks',
        'id_rsa*',
        'id_ed25519*',
        'id_ecdsa*',
        '.ssh/*',
        '.aws/*',
        '.gnupg/*',
        '.git-credentials',
        '.netrc',
        '.npmrc',
        '.docker/config.json',
        'auth.json',
        'config/secrets/*',
        'config/jwt/*',
    ];

    /**
     * Executables the model is allowed to start (basename or workspace-relative path).
     *
     * @var list<string>
     */
    public const DEFAULT_ALLOWED_COMMANDS = [
        'php',
        'bin/console',
        'bin/composer',
        'composer',
        'vendor/bin/phpunit',
        'vendor/bin/phpstan',
        'vendor/bin/phpcs',
        'vendor/bin/php-cs-fixer',
        'vendor/bin/rector',
        'node',
        'npm',
        'npx',
        'git',
    ];

    /**
     * Regexes evaluated against the full command line; a match rejects the call.
     *
     * @var list<string>
     */
    public const DEFAULT_DENIED_COMMAND_PATTERNS = [
        // Never mutate remote state.
        '/\bgit\s+push\b/',
        '/\bgit\s+remote\s+(add|set-url|remove|rename)\b/',
        // Never rewrite or destroy local history/work.
        '/\bgit\s+(commit|reset\s+--hard|clean\s+-[a-z]*f|checkout\s+--|restore\s+--source'
        . '|rebase|merge|cherry-pick)\b/',
        // No privilege escalation, no shell indirection, no downloads.
        '/\b(sudo|su|doas)\b/',
        '/\b(sh|bash|zsh|dash|fish|env)\s+-c\b/',
        '/\b(curl|wget|nc|netcat|ssh|scp|rsync|telnet)\b/',
        '/\brm\s+-[a-zA-Z]*r[a-zA-Z]*f?\s+\/(?!tmp)/',
        '/[;&|<>`]/',
        '/\$\{?\w/',
        '/\.\.\//',
    ];

    /**
     * Git subcommands the run_command tool accepts.
     *
     * @var list<string>
     */
    public const DEFAULT_GIT_SUBCOMMANDS = [
        'status',
        'diff',
        'log',
        'show',
        'ls-files',
        'grep',
        'rev-parse',
        'describe',
        'blame',
        'shortlog',
        'branch',
        'tag',
    ];

    /**
     * Composer subcommands the run_command tool accepts.
     *
     * @var list<string>
     */
    public const DEFAULT_COMPOSER_SUBCOMMANDS = [
        'install',
        'update',
        'validate',
        'dump-autoload',
        'show',
        'outdated',
        'audit',
        'check-platform-reqs',
        'why',
    ];

    /**
     * NPM subcommands the run_command tool accepts.
     *
     * @var list<string>
     */
    public const DEFAULT_NPM_SUBCOMMANDS = [
        'ci',
        'install',
        'test',
        'run',
        'ls',
        'outdated',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cable_car');

        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('workspaces_root')
                    ->info('Directory containing the workspaces the agent is allowed to touch.')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('default_model')
                    ->info('Name of the model used when a run does not specify one.')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('models')
                    ->info('Models exposed to the agent, keyed by a short name.')
                    ->useAttributeAsKey('name')
                    ->defaultValue([
                        'default' => [
                            'label' => 'Ollama · local',
                            'platform' => 'ai.platform.ollama',
                            'model' => 'gemma4:12b',
                        ],
                    ])
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')
                                ->info('Human readable label (used by the UI selector).')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('platform')
                                ->info('Symfony AI platform service id (e.g. ai.platform.ollama).')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('model')
                                ->info('Model name understood by the platform.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('options')
                                ->info('Extra provider options forwarded as-is (e.g. num_predict, temperature, think).')
                                ->useAttributeAsKey('name')
                                ->variablePrototype()->end()
                                ->defaultValue([])
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_iterations')->defaultValue(20)->min(1)->end()
                        ->integerNode('max_tool_calls')->defaultValue(50)->min(1)->end()
                        ->integerNode('max_tool_calls_per_turn')->defaultValue(4)->min(1)->end()
                        ->integerNode('max_run_seconds')->defaultValue(600)->min(1)->end()
                        ->integerNode('max_command_seconds')->defaultValue(120)->min(1)->end()
                        ->integerNode('max_tool_output_bytes')->defaultValue(32768)->min(256)->end()
                        ->integerNode('max_file_read_bytes')->defaultValue(131072)->min(256)->end()
                        ->integerNode('max_file_write_bytes')->defaultValue(262144)->min(256)->end()
                        ->integerNode('max_list_entries')->defaultValue(500)->min(1)->end()
                        ->integerNode('max_search_results')->defaultValue(100)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('workspace')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('ignored_directories')
                            ->info('Directories never traversed by list_files/search.')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_IGNORED_DIRECTORIES)
                        ->end()
                        ->arrayNode('denied_paths')
                            ->info('Paths the tools refuse to read or write (fnmatch patterns).')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_DENIED_PATHS)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('commands')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allow')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_ALLOWED_COMMANDS)
                        ->end()
                        ->arrayNode('deny_patterns')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_DENIED_COMMAND_PATTERNS)
                        ->end()
                        ->arrayNode('git_subcommands')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_GIT_SUBCOMMANDS)
                        ->end()
                        ->arrayNode('composer_subcommands')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_COMPOSER_SUBCOMMANDS)
                        ->end()
                        ->arrayNode('npm_subcommands')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_NPM_SUBCOMMANDS)
                        ->end()
                        ->scalarNode('path')
                            ->info('PATH given to spawned commands (no host PATH inheritance).')
                            ->defaultValue('/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
