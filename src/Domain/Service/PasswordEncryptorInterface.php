<?php

declare(strict_types=1);

namespace App\Domain\Service;

interface PasswordEncryptorInterface
{
    public function encrypt(string $plainPassword): string;

    public function decrypt(string $encryptedPassword): string;
}
