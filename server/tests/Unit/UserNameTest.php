<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

final class UserNameTest extends TestCase
{
    public function test_name_combines_the_authoritative_name_fields(): void
    {
        $user = new User([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('Ada Lovelace', $user->toArray()['name']);
    }

    public function test_legacy_name_assignment_splits_on_the_first_whitespace(): void
    {
        $user = new User(['name' => 'Ada Byron Lovelace']);

        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Byron Lovelace', $user->last_name);
        $this->assertSame('Ada Byron Lovelace', $user->name);
    }
}
