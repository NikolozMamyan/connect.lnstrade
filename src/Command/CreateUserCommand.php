<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Cree un utilisateur applicatif avec mot de passe hashe.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email de connexion')
            ->addArgument('password', InputArgument::REQUIRED, 'Mot de passe en clair')
            ->addArgument('roles', InputArgument::IS_ARRAY, 'Roles optionnels, ex: ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $plainPassword = (string) $input->getArgument('password');
        $roles = array_values(array_filter(array_map('trim', (array) $input->getArgument('roles'))));

        if ($email === '' || $plainPassword === '') {
            $io->error('Email et mot de passe obligatoires.');

            return Command::INVALID;
        }

        if ($this->userRepository->findOneByEmail($email) instanceof User) {
            $io->error(sprintf('Un utilisateur existe deja pour "%s".', $email));

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles !== [] ? $roles : ['ROLE_USER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Utilisateur cree : %s', $email));

        return Command::SUCCESS;
    }
}
