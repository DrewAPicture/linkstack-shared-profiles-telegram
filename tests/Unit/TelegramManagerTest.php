<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Models\TelegramManager;

#[CoversClass(TelegramManager::class)]
final class TelegramManagerTest extends TestCase
{
    public function testTimestampsIsDisabled(): void
    {
        $manager = new TelegramManager;

        $this->assertFalse($manager->timestamps);
    }

    public function testFillable(): void
    {
        $manager = new TelegramManager;

        $this->assertSame(
            ['telegram_id', 'profile_id', 'role', 'added_by'],
            $manager->getFillable()
        );
    }

    public function testIsOwnerReturnsTrueForOwnerRole(): void
    {
        $manager = new TelegramManager;
        $manager->role = 'owner';

        $this->assertTrue($manager->isOwner());
    }

    public function testIsOwnerReturnsFalseForModeratorRole(): void
    {
        $manager = new TelegramManager;
        $manager->role = 'moderator';

        $this->assertFalse($manager->isOwner());
    }
}
