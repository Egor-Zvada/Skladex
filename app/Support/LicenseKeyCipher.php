<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use RuntimeException;

class LicenseKeyCipher
{
    private function cipher(): Encrypter
    {
        $encoded = (string) config('skladex.license_master_key');
        $key = str_starts_with($encoded, 'base64:') ? base64_decode(substr($encoded, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Ключ защиты лицензий не настроен. Обратитесь к администратору.');
        }

        return new Encrypter($key, 'AES-256-GCM');
    }

    public function encrypt(string $plaintext): string
    {
        return $this->cipher()->encryptString($plaintext);
    }

    public function decrypt(string $ciphertext, string $keyId): string
    {
        if ($keyId !== config('skladex.license_key_id')) {
            throw new RuntimeException('Для этой записи требуется ключ прежней версии.');
        }

        return $this->cipher()->decryptString($ciphertext);
    }
}
