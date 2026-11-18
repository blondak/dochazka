<?php

declare(strict_types=1);

namespace Dochazka\Identity;

use Dochazka\Http\Problem;
use Doctrine\ORM\EntityManagerInterface;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Nette\Http\Session;

final class Passkeys
{
    private ?string $rpId = null;

    public function __construct(
        private EntityManagerInterface $em,
        private Session $session,
        private Accounts $accounts,
        private string $origin,
    ) {
        $url = parse_url($origin);
        if (isset($url['scheme'], $url['host']) && ($url['scheme'] === 'https' || ($url['scheme'] === 'http' && $url['host'] === 'localhost'))
            && $origin === $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '')) {
            $this->rpId = $url['host'];
        }
    }

    public function status(): array
    {
        return ['available' => $this->rpId !== null];
    }

    public function registrationOptions(#[\SensitiveParameter] array $input): object
    {
        $webauthn = $this->engine();
        $employee = $this->accounts->verifyOwnPassword($input['password'] ?? '');
        $label = trim($input['label'] ?? '');
        if ($label === '' || mb_strlen($label) > 80) {
            throw new Problem('Zadejte název passkey do 80 znaků.');
        }
        $keys = $this->em->getRepository(Passkey::class)->findBy(['employee' => $employee]);
        $excluded = array_map(static fn(Passkey $key): string => self::decode($key->id), $keys);
        $options = $webauthn->getCreateArgs(pack('N', $employee->id), $employee->login, $employee->name, 90, true, true, null, $excluded);
        $this->remember($webauthn, 'create', $employee->id, $label);
        return $options;
    }

    public function register(array $input): array
    {
        $webauthn = $this->engine();
        $employee = $this->em->getReference(\Dochazka\Staff\Employee::class, $this->accounts->actor()['id']);
        $flow = $this->consume('create', $employee->id);
        try {
            $clientData = $this->clientData($input['clientDataJSON'] ?? '');
            $result = $webauthn->processCreate($clientData, self::decode($input['attestationObject'] ?? ''), $flow['challenge'], true);
        } catch (WebAuthnException) {
            throw new Problem('Passkey se nepodařilo ověřit.', 403);
        }
        $id = rtrim(strtr(base64_encode($result->credentialId), '+/', '-_'), '=');
        $this->em->persist(new Passkey($id, $employee, $result->credentialPublicKey, $result->signatureCounter, $flow['label']));
        return ['ok' => true];
    }

    public function authenticationOptions(): object
    {
        $webauthn = $this->engine();
        $options = $webauthn->getGetArgs(timeout: 90, requireUserVerification: true);
        $this->remember($webauthn, 'login', null);
        return $options;
    }

    public function authenticate(array $input): array
    {
        $webauthn = $this->engine();
        $flow = $this->consume('login', null);
        $key = $this->em->find(Passkey::class, (string) ($input['id'] ?? ''));
        if (!$key) {
            throw new Problem('Passkey se nepodařilo ověřit.', 401);
        }
        try {
            $clientData = $this->clientData($input['clientDataJSON'] ?? '');
            if (!hash_equals(pack('N', $key->employee->id), self::decode($input['userHandle'] ?? ''))) {
                throw new Problem('Passkey se nepodařilo ověřit.', 401);
            }
            $webauthn->processGet(
                $clientData, self::decode($input['authenticatorData'] ?? ''), self::decode($input['signature'] ?? ''),
                $key->publicKey, $flow['challenge'], $key->signatureCounter, true,
            );
        } catch (WebAuthnException) {
            throw new Problem('Passkey se nepodařilo ověřit.', 401);
        }
        $key->signatureCounter = $webauthn->getSignatureCounter();
        $this->em->flush();
        return $this->accounts->establishSession($key->employee);
    }

    public function listing(): array
    {
        $employee = $this->accounts->actor()['id'];
        $keys = $this->em->getRepository(Passkey::class)->findBy(['employee' => $employee], ['createdAt' => 'DESC']);
        return ['keys' => array_map(static fn(Passkey $key): array => [
            'id' => $key->id,
            'label' => $key->label,
            'created' => $key->createdAt->format(DATE_ATOM),
        ], $keys)];
    }

    public function remove(array $input): array
    {
        $owner = $this->accounts->actor()['id'];
        $key = $this->em->find(Passkey::class, (string) ($input['id'] ?? ''));
        if (!$key || $key->employee->id !== $owner) {
            throw new Problem('Passkey není dostupná.', 404);
        }
        $this->em->remove($key);
        return ['ok' => true];
    }

    private function engine(): WebAuthn
    {
        if ($this->rpId === null) {
            throw new Problem('Přihlášení pomocí passkeys zatím není nastavené.', 409);
        }
        return new WebAuthn('Docházka', $this->rpId, ['none'], true);
    }

    private function remember(WebAuthn $webauthn, string $type, ?int $employee, string $label = ''): void
    {
        $section = $this->session->getSection('passkeys');
        $section->set('flow', [
            'challenge' => $webauthn->getChallenge()->getBinaryString(),
            'type' => $type,
            'employee' => $employee,
            'label' => $label,
            'expires' => time() + 120,
        ], '2 minutes');
    }

    private function consume(string $type, ?int $employee): array
    {
        $section = $this->session->getSection('passkeys');
        $flow = $section->get('flow');
        $section->remove('flow');
        if (!$flow || $flow['type'] !== $type || $flow['employee'] !== $employee || $flow['expires'] <= time()) {
            throw new Problem('Ověření vypršelo. Zkuste to znovu.', 409);
        }
        return $flow;
    }

    private function clientData(string $encoded): string
    {
        $clientData = self::decode($encoded);
        $fields = json_decode($clientData, true, flags: JSON_THROW_ON_ERROR);
        if (($fields['origin'] ?? null) !== $this->origin || ($fields['crossOrigin'] ?? false)) {
            throw new Problem('Původ ověření neodpovídá adrese docházky.', 403);
        }
        return $clientData;
    }

    private static function decode(string $value): string
    {
        return ByteBuffer::fromBase64Url($value)->getBinaryString();
    }
}
