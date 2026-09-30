<?php

namespace App\Tests\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Foundry factory for App\Entity\User.
 *
 * Passwords are stored plaintext by default; hash them before persisting, e.g. in a test:
 *
 *     $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
 *     UserFactory::createOne([
 *         'email' => 'user@example.com',
 *         'password' => $hasher->hashPassword(new User(), 'secret'),
 *     ]);
 */
/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'roles' => ['ROLE_USER'],
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'isActive' => true,
            'password' => self::faker()->password(),
        ];
    }
}
