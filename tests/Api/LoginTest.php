<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\AbstractApiTestCase;
use App\Tests\Factory\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginTest extends AbstractApiTestCase
{
    /**
     * @param list<string> $roles
     */
    private function createUser(
        string $email = 'user@example.com',
        bool $active = true,
        array $roles = ['ROLE_USER'],
    ): void {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        UserFactory::createOne([
            'email' => $email,
            'password' => $hasher->hashPassword(new User(), 'secret'),
            'roles' => $roles,
            'isActive' => $active,
        ]);
    }

    public function testLoginReturnsToken(): void
    {
        $this->createUser();

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'user@example.com', 'password' => 'secret'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('token', $response->toArray());
    }

    public function testLoginWithBadCredentialsReturns401(): void
    {
        $this->createUser();

        static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'user@example.com', 'password' => 'wrong'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginOfDisabledUserReturns401(): void
    {
        $this->createUser(active: false);

        static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'user@example.com', 'password' => 'secret'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLoginWithoutCredentialsReturns401(): void
    {
        static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => '', 'password' => ''],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
