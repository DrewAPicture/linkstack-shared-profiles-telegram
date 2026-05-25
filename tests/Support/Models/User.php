<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Support\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use WerdsWords\LinkStack\SharedProfiles\Concerns\HasApiToken;
use WerdsWords\LinkStack\SharedProfiles\Contracts\HasApiTokenContract;

class User extends Authenticatable implements HasApiTokenContract
{
    use HasApiToken;

    protected $fillable = ['name', 'email', 'auto_approve', 'api_token'];
}
