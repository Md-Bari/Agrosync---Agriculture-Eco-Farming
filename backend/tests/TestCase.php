<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Authenticate subsequent API requests as the user with this public id. */
    protected function actingAsApiUser(string $publicId): static
    {
        $plainToken = Str::random(64);

        DB::table('api_tokens')->insert([
            'user_public_id' => $publicId,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->withToken($plainToken);
    }
}
