<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends AbstractFixtures
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setFirstName('Admin');
        $admin->setLastName('CableCar');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'password'));

        $manager->persist($admin);

        for ($i = 1; $i <= 3; ++$i) {
            $user = new User();
            $user->setEmail(sprintf('user%d@example.com', $i));
            $user->setFirstName($this->faker->firstName());
            $user->setLastName($this->faker->lastName());
            $user->setRoles(['ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));

            $manager->persist($user);
        }

        $inactive = new User();
        $inactive->setEmail('inactive@example.com');
        $inactive->setFirstName('Inactive');
        $inactive->setLastName('User');
        $inactive->setRoles(['ROLE_USER']);
        $inactive->setIsActive(false);
        $inactive->setPassword($this->passwordHasher->hashPassword($inactive, 'password'));

        $manager->persist($inactive);

        $manager->flush();
    }
}
