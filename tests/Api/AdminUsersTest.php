<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\AbstractApiTestCase;
use App\Tests\Factory\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminUsersTest extends AbstractApiTestCase
{
    private function createAdminToken(): string
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        UserFactory::createOne([
            'email' => 'admin@example.com',
            'password' => $hasher->hashPassword(new User(), 'secret'),
            'roles' => ['ROLE_ADMIN'],
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'admin@example.com', 'password' => 'secret'],
        ]);

        return $response->toArray()['token'];
    }

    private function createUserToken(): string
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        UserFactory::createOne([
            'email' => 'user@example.com',
            'password' => $hasher->hashPassword(new User(), 'secret'),
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'user@example.com', 'password' => 'secret'],
        ]);

        return $response->toArray()['token'];
    }

    public function testListRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/users');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListRequiresAdminRole(): void
    {
        $token = $this->createUserToken();

        static::createClient()->request('GET', '/api/users', ['headers' => ['Authorization' => 'Bearer ' . $token]]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListReturnsPaginatedUsersWithoutPassword(): void
    {
        $adminToken = $this->createAdminToken();
        for ($i = 1; $i <= 5; ++$i) {
            UserFactory::createOne(['email' => sprintf('member%d@example.com', $i)]);
        }

        $response = static::createClient()->request(
            'GET',
            '/api/users?itemsPerPage=3&page=1',
            ['headers' => ['Authorization' => 'Bearer ' . $adminToken]],
        );

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame(6, $data['totalItems']);
        $this->assertCount(3, $data['member']);
        $this->assertArrayHasKey('view', $data);
        $this->assertArrayNotHasKey('password', $data['member'][0]);
    }

    public function testListFiltersByEmail(): void
    {
        $adminToken = $this->createAdminToken();
        UserFactory::createOne(['email' => 'alice@example.com']);
        UserFactory::createOne(['email' => 'bob@example.com']);

        $response = static::createClient()->request(
            'GET',
            '/api/users?email=ali&itemsPerPage=50',
            ['headers' => ['Authorization' => 'Bearer ' . $adminToken]],
        );

        $this->assertResponseStatusCodeSame(200);
        $emails = array_column($response->toArray()['member'], 'email');
        $this->assertContains('alice@example.com', $emails);
        $this->assertNotContains('bob@example.com', $emails);
    }

    public function testAdminCanDisableAndReenableAUser(): void
    {
        $adminToken = $this->createAdminToken();
        $target = UserFactory::createOne(['email' => 'target@example.com']);

        static::createClient()->request('PATCH', sprintf('/api/users/%d', $target->getId()), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['isActive' => false],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['isActive' => false]);

        static::createClient()->request('PATCH', sprintf('/api/users/%d', $target->getId()), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['isActive' => true],
        ]);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['isActive' => true]);
    }

    public function testDisabledUserCannotLogInAfterAdminPatch(): void
    {
        $adminToken = $this->createAdminToken();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $target = UserFactory::createOne([
            'email' => 'victim@example.com',
            'password' => $hasher->hashPassword(new User(), 'secret'),
        ]);

        static::createClient()->request('PATCH', sprintf('/api/users/%d', $target->getId()), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['isActive' => false],
        ]);
        $this->assertResponseStatusCodeSame(200);

        static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'victim@example.com', 'password' => 'secret'],
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAdminCanChangeRoles(): void
    {
        $adminToken = $this->createAdminToken();
        $target = UserFactory::createOne(['email' => 'promotee@example.com']);

        static::createClient()->request('PATCH', sprintf('/api/users/%d', $target->getId()), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['roles' => ['ROLE_ADMIN']],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
    }

    public function testAdminCanDeleteAUser(): void
    {
        $adminToken = $this->createAdminToken();
        $target = UserFactory::createOne(['email' => 'doomed@example.com']);

        static::createClient()->request('DELETE', sprintf('/api/users/%d', $target->getId()), [
            'headers' => ['Authorization' => 'Bearer ' . $adminToken],
        ]);

        $this->assertResponseStatusCodeSame(204);
    }
}
