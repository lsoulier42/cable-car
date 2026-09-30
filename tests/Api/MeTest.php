<?php

namespace App\Tests\Api;

use App\Entity\User;
use App\Tests\AbstractApiTestCase;
use App\Tests\Factory\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MeTest extends AbstractApiTestCase
{
    private function createUserAndToken(string $email = 'user@example.com'): string
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        UserFactory::createOne([
            'email' => $email,
            'password' => $hasher->hashPassword(new User(), 'secret'),
            'firstName' => 'Jane',
            'lastName' => 'Doe',
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => $email, 'password' => 'secret'],
        ]);

        return $response->toArray()['token'];
    }

    /**
     * @return array{Authorization: string}
     */
    private function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function testMeRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/me');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsTheCurrentUserProfile(): void
    {
        $token = $this->createUserAndToken();

        $response = static::createClient()->request('GET', '/api/me', ['headers' => $this->authHeaders($token)]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains([
            'email' => 'user@example.com',
            'firstName' => 'Jane',
            'lastName' => 'Doe',
            'roles' => ['ROLE_USER'],
        ]);
        $this->assertArrayNotHasKey('password', $response->toArray());
    }

    public function testPatchMeUpdatesProfileFields(): void
    {
        $token = $this->createUserAndToken();

        static::createClient()->request('PATCH', '/api/me', [
            'headers' => $this->authHeaders($token),
            'json' => ['email' => 'user@example.com', 'firstName' => 'Janet', 'lastName' => 'Doe'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains(['firstName' => 'Janet']);
    }

    public function testPatchMeCanChangePassword(): void
    {
        $token = $this->createUserAndToken();

        static::createClient()->request('PATCH', '/api/me', [
            'headers' => $this->authHeaders($token),
            'json' => ['email' => 'user@example.com', 'plainPassword' => 'newsecret123'],
        ]);

        $this->assertResponseStatusCodeSame(200);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'user@example.com', 'password' => 'newsecret123'],
        ]);

        $this->assertResponseStatusCodeSame(200);
    }

    public function testPatchMeWithTakenEmailReturns422(): void
    {
        UserFactory::createOne(['email' => 'other@example.com']);
        $token = $this->createUserAndToken();

        static::createClient()->request('PATCH', '/api/me', [
            'headers' => $this->authHeaders($token),
            'json' => ['email' => 'other@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'email'],
            ],
        ]);
    }
}
