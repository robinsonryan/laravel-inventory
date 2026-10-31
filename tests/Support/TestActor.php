<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A person, as far as the matching actions care: something with a morph class
 * and a key. It has no table because the actions only record who acted.
 */
final class TestActor extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public static function named(string $id = '019fdd00-0000-7000-8000-00000000a001'): self
    {
        $actor = new self;
        $actor->setAttribute('id', $id);

        return $actor;
    }
}
