<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $telegram_id
 * @property int $profile_id
 * @property string $role
 * @property int|null $added_by
 * @property Carbon $created_at
 */
class Manager extends Model
{
    protected $table = 'telegram_managers';

    public $timestamps = false;

    protected $fillable = [
        'telegram_id',
        'profile_id',
        'role',
        'added_by',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'added_by' => 'integer',
    ];

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
