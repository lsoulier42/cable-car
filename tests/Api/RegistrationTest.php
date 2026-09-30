<?php

namespace App\Tests\Api;

use App\Tests\AbstractApiTestCase;
use App\Tests\Factory\UserFactory;

final class RegistrationTest extends AbstractApiTestCase
{
    public function testRegisterCreatesAnActiveUser(): void
    {
        $response = static::createClient()->request('POST', '/api/register', [
            'json' => [
                'email' => 'new@example.com',
                'plainPassword' => 'secret123',
                'firstName' => 'New',
                'lastName' => 'User',
            ],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains([
            'email' => 'new@example.com',
            'firstName' => 'New',
            'lastName' => 'User',
            'roles' => ['ROLE_USER'],
            'isActive' => true,
        ]);
        $this->assertArrayNotHasKey('password', $response->toArray());
    }

    public function testRegisteredUserCanLogIn(): void
    {
        static::createClient()->request('POST', '/api/register', [
            'json' => ['email' => 'new@example.com', 'plainPassword' => 'secret123'],
        ]);

        $response = static::createClient()->request('POST', '/api/login', [
            'json' => ['email' => 'new@example.com', 'password' => 'secret123'],
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertArrayHasKey('token', $response->toArray());
    }

    public function testRegisterWithDuplicateEmailReturns422(): void
    {
        UserFactory::createOne(['email' => 'taken@example.com']);

        static::createClient()->request('POST', '/api/register', [
            'json' => ['email' => 'taken@example.com', 'plainPassword' => 'secret123'],
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertJsonContains([
            'violations' => [
                ['propertyPath' => 'email'],
            ],
        ]);
    }

    public function testRegisterWithInvalidPayloadReturns422(): void
    {
        static::createClient()->request('POST', '/api/register', [
            'json' => ['email' => 'not-an-email', 'plainPassword' => 'short'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterForcesRoleUser(): void
    {
        static::createClient()->request('POST', '/api/register', [
            'json' => ['email' => 'sneaky@example.com', 'plainPassword' => 'secret123', 'roles' => ['ROLE_ADMIN']],
        ]);

        $this->assertResponseStatusCodeSame(201);
        $this->assertJsonContains(['roles' => ['ROLE_USER']]);
    }
}
