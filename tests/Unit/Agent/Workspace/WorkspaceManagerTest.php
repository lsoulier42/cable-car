<?php

namespace App\Tests\Unit\Agent\Workspace;

use App\Agent\Exception\WorkspaceException;
use App\Agent\Workspace\WorkspaceManager;
use PHPUnit\Framework\TestCase;

final class WorkspaceManagerTest extends TestCase
{
    private string $root;

    private WorkspaceManager $manager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cable-car-workspaces-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/demo-repo', 0o775, true);
        mkdir($this->root . '/.hidden-repo', 0o775, true);
        file_put_contents($this->root . '/not-a-directory.txt', 'x');

        $this->manager = new WorkspaceManager($this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/not-a-directory.txt');
        @rmdir($this->root . '/demo-repo');
        @rmdir($this->root . '/.hidden-repo');
        @rmdir($this->root);
    }

    public function testItListsVisibleDirectoriesAsWorkspaces(): void
    {
        $workspaces = $this->manager->listWorkspaces();

        self::assertCount(1, $workspaces);
        self::assertSame('demo-repo', $workspaces[0]->getId());
        self::assertSame($this->root . '/demo-repo', $workspaces[0]->getRoot());
    }

    public function testItResolvesAWorkspaceByName(): void
    {
        $workspace = $this->manager->get('demo-repo');

        self::assertSame('demo-repo', $workspace->getId());
        self::assertTrue($workspace->exists());
    }

    public function testItRejectsTraversalAndUnknownNames(): void
    {
        foreach (['..', '../demo-repo', 'a/b', '.hidden-repo', '', "demo\0repo", 'demo-repo/../..'] as $name) {
            try {
                $this->manager->get($name);
                self::fail(sprintf('"%s" should have been refused.', $name));
            } catch (WorkspaceException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(WorkspaceException::class);
        $this->manager->get('missing-repo');
    }

    public function testItRefusesSymlinkedWorkspaces(): void
    {
        symlink($this->root . '/demo-repo', $this->root . '/linked-repo');

        $this->expectException(WorkspaceException::class);

        try {
            $this->manager->get('linked-repo');
        } finally {
            @unlink($this->root . '/linked-repo');
        }
    }

    public function testItWrapsAnArbitraryDirectoryForTheCli(): void
    {
        $workspace = $this->manager->fromPath($this->root . '/demo-repo');

        self::assertSame('demo-repo', $workspace->getId());
        self::assertSame((string) realpath($this->root . '/demo-repo'), $workspace->getRoot());
    }

    public function testItCreatesTheWorkspacesRootOnDemand(): void
    {
        $root = sys_get_temp_dir() . '/cable-car-new-root-' . bin2hex(random_bytes(4));
        $manager = new WorkspaceManager($root . '/nested');

        self::assertSame($root . '/nested', $manager->getRoot());
        self::assertDirectoryExists($root . '/nested');

        rmdir($root . '/nested');
        rmdir($root);
    }
}
