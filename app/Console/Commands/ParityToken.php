<?php

namespace App\Console\Commands;

use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Http\ApiError;
use App\Http\Json;
use Illuminate\Console\Command;

class ParityToken extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:token
                            {action : encode or decode}
                            {issuer : The issuer suffix, e.g. admin or login}
                            {value : The subject to encode, or the token to decode}
                            {--minutes=20 : Lifetime of an encoded token}';

    /**
     * @var string
     */
    protected $description = 'Encode or decode a token with the RSA key of the data folder, to compare with Vaultwarden';

    public function handle(Jwt $jwt): int
    {
        $issuer = JwtIssuer::tryFrom((string) $this->argument('issuer'));
        $value = (string) $this->argument('value');
        if ($issuer === null) {
            $this->components->error('Unknown issuer.');

            return self::INVALID;
        }

        if ($this->argument('action') === 'encode') {
            $now = time();
            $this->line($jwt->encode([
                'nbf' => $now,
                'exp' => $now + (int) $this->option('minutes') * 60,
                'iss' => $jwt->issuer($issuer),
                'sub' => $value,
            ]));

            return self::SUCCESS;
        }

        try {
            $this->line(Json::encode($jwt->decode($value, $issuer)));
        } catch (ApiError $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
