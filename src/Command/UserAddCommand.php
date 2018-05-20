<?php

namespace App\Command;

use App\Entity\Domain;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserAddCommand extends Command
{
    private $manager;

    private $validator;

    public function __construct(string $name = null, EntityManagerInterface $manager, ValidatorInterface $validator)
    {
        parent::__construct($name);

        $this->manager = $manager;
        $this->validator = $validator;
    }

    protected function configure(): void
    {
        $this
            ->setName('user:add')
            ->setDescription('Add users.')
            ->addArgument('name', InputArgument::REQUIRED, 'Local-part (before @)')
            ->addArgument('domain', InputArgument::REQUIRED, 'Domain-part (after @), has to be created already')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Allow login to management interface')
            ->addOption('sendonly', null, InputOption::VALUE_NONE, 'Send only accounts cannot receive mails')
            ->addOption('quota', null, InputOption::VALUE_REQUIRED, 'Limit the disk usage of this account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Sets the account password directly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User();
        $domain = $this->getDomain($input->getArgument('domain'));

        if (!$domain) {
            $output->writeln(sprintf('<error>Domain %s was not found.</error>', $input->getArgument('domain')));

            return 1;
        }

        $user->setDomain($domain);
        $user->setName(\mb_strtolower($input->getArgument('name')));
        $user->setAdmin((bool)$input->getOption('admin'));
        $user->setSendOnly((bool)$input->getOption('sendonly'));

        if ($input->hasOption('quota')) {
            $user->setQuota((int)$input->getOption('quota'));
        }

        if (!$input->getOption('password')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new Question('Password? (hidden)');
            $question->setHidden(true);

            $password = $helper->ask($input, $output, $question);

            if (!$password) {
                $output->writeln('<error>Please set a valid password.</error>');

                return 1;
            }

            $user->setPlainPassword($password);
        } else {
            $user->setPlainPassword($input->getOption('password'));
        }

        $validationResult = $this->validator->validate($user);

        if ($validationResult->count() > 0) {
            foreach ($validationResult as $item) {
                /** @var $item ConstraintViolation */
                $output->writeln(sprintf('<error>%s: %s</error>', $item->getPropertyPath(), $item->getMessage()));
            }

            return 1;
        }

        $this->manager->persist($user);
        $this->manager->flush();

        return 0;
    }

    private function getDomain(string $domain): ?Domain
    {
        return $this->manager->getRepository(Domain::class)->findOneBy(['name' => \mb_strtolower($domain)]);
    }
}
