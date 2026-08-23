<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Service\PasswordEncryptorInterface;

class OpenSslPasswordEncryptor implements PasswordEncryptorInterface
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = substr(hash('sha256', $appSecret), 0, 32);
    }

    public function encrypt(string $plainPassword): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plainPassword, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $ciphertext);
    }

    public function decrypt(string $encryptedPassword): string
    {
        $data = base64_decode($encryptedPassword);
        if (strlen($data) < 17) {
            return '';
        }
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            return '';
        }
        return $plaintext;
    }
}
