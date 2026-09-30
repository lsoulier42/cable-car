<?php

namespace App\Tests\Unit\Agent\Workspace;

use App\Agent\Exception\PathViolationException;
use App\Agent\Workspace\PathGuard;
use App\Tests\Support\TempWorkspace;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    use TempWorkspace;

    private PathGuard $guard;

    protected function setUp(): void
    {
        $workspace = $this->createTempWorkspace([
            'src/Controller/MeController.php' => "<?php\n",
            '.env' => "APP_SECRET=super-secret\n",
            'config/jwt/private.pem' => "-----BEGIN KEY-----\n",
            'vendor/autoload.php' => "<?php\n",
            'node_modules/pkg/index.js' => "module.exports = {};\n",
        ]);

        $this->guard = new PathGuard(
            $workspace->getRoot(),
            ['*.pem', '.env', '.env.*', 'config/jwt/*'],
            ['.git', 'vendor', 'node_modules'],
        );
    }

    protected function tearDown(): void
    {
        $this->removeTempWorkspace();
    }

    public function testItResolvesWorkspaceRelativePaths(): void
    {
        $root = $this->guard->getRoot();

        self::assertSame($root, $this->guard->resolve('.'));
        self::assertSame(
            $root . '/src/Controller/MeController.php',
            $this->guard->resolve('src/Controller/MeController.php'),
        );
        self::assertSame($root . '/src', $this->guard->resolve('src/./Controller/..'));
    }

    public function testItRejectsParentTraversal(): void
    {
        $this->expectException(PathViolationException::class);

        $this->guard->resolve('../../etc/passwd');
    }

    public function testItRejectsAbsolutePaths(): void
    {
        $this->expectException(PathViolationException::class);

        $this->guard->resolve('/etc/passwd');
    }

    public function testItRejectsNulBytes(): void
    {
        $this->expectException(PathViolationException::class);

        $this->guard->resolve("src/Controller\0/MeController.php");
    }

    public function testItRejectsMissingFilesWhenRequired(): void
    {
        $this->expectException(PathViolationException::class);

        $this->guard->resolve('src/Missing.php', true);
    }

    public function testItRejectsSensitivePaths(): void
    {
        foreach (['.env', '.env.test', 'config/jwt/private.pem', 'deploy/key.pem'] as $path) {
            try {
                $this->guard->resolve($path);
                self::fail(sprintf('"%s" should have been denied.', $path));
            } catch (PathViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testItDetectsDeniedPathsWithoutResolving(): void
    {
        self::assertTrue($this->guard->isDenied('.env'));
        self::assertTrue($this->guard->isDenied('deploy/ssh/id_rsa.pem'));
        self::assertFalse($this->guard->isDenied('src/Controller/MeController.php'));
    }

    public function testItDetectsIgnoredDirectories(): void
    {
        self::assertTrue($this->guard->isIgnored('vendor'));
        self::assertTrue($this->guard->isIgnored('vendor/autoload.php'));
        self::assertTrue($this->guard->isIgnored('frontend/node_modules/react/index.js'));
        self::assertFalse($this->guard->isIgnored('src/Controller/MeController.php'));
    }

    public function testItRejectsSymlinkEscapes(): void
    {
        $outside = sys_get_temp_dir() . '/cable-car-outside-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, "outside\n");
        symlink($outside, $this->guard->getRoot() . '/escape.txt');
        symlink(\dirname($outside), $this->guard->getRoot() . '/escape-dir');

        try {
            $this->guard->resolve('escape.txt', true);
            self::fail('A symlink pointing outside the workspace must be rejected.');
        } catch (PathViolationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->guard->resolve('escape-dir/anything.txt');
            self::fail('A symlinked directory pointing outside the workspace must be rejected.');
        } catch (PathViolationException) {
            $this->addToAssertionCount(1);
        }

        @unlink($this->guard->getRoot() . '/escape.txt');
        @unlink($this->guard->getRoot() . '/escape-dir');
        @unlink($outside);
    }

    public function testItAllowsWritesToNewFilesInsideTheWorkspace(): void
    {
        $resolved = $this->guard->resolve('src/Command/VersionCommand.php');

        self::assertSame($this->guard->getRoot() . '/src/Command/VersionCommand.php', $resolved);
    }

    public function testItRendersRelativePaths(): void
    {
        $absolute = $this->guard->resolve('src/Controller/MeController.php');

        self::assertSame('src/Controller/MeController.php', $this->guard->relative($absolute));
    }
}
