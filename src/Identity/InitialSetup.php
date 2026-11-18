<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Dochazka\Http\Problem;
use Dochazka\Staff\Employee;
use Doctrine\ORM\EntityManagerInterface;

final class InitialSetup
{
    public function __construct(
        private EntityManagerInterface $em,
        #[\SensitiveParameter] private string $token,
    ) {}

    public function status(): array
    {
        return ['available' => preg_match('/\A[a-f0-9]{64}\z/', $this->token) === 1
            && $this->em->getRepository(Employee::class)->count() === 0];
    }

    public function create(array $input): array
    {
        if (!$this->status()['available']) {
            throw new Problem('První nastavení již není dostupné.', 409);
        }
        if (!hash_equals($this->token, $input['token'] ?? '')) {
            throw new Problem('Neplatný instalační kód.', 403);
        }

        $name = trim($input['name'] ?? '');
        $login = trim($input['login'] ?? '');
        $password = $input['password'] ?? '';
        if ($name === '' || strlen($name) > 200 || $login === '' || strlen($login) > 200) {
            throw new Problem('Vyplňte jméno a přihlašovací jméno, každé nejvýše 200 bajtů.');
        }
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem('Heslo musí mít 12 až 72 bajtů.');
        }

        $this->em->persist(Employee::administrator($login, $name, $password));
        return ['ok' => true];
    }
}
