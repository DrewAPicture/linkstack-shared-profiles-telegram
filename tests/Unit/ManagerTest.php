<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Models\Manager;

#[CoversClass(Manager::class)]
final class ManagerTest extends TestCase
{
    public function testTimestampsIsDisabled(): void
    {
        $manager = new Manager;

        $this->assertFalse($manager->timestamps);
    }

    public function testFillable(): void
    {
        $manager = new Manager;

        $this->assertSame(
            ['telegram_id', 'profile_id', 'role', 'added_by'],
            $manager->getFillable()
        );
    }

    public function testIsOwnerReturnsTrueForOwnerRole(): void
    {
        $manager = new Manager;
        $manager->role = 'owner';

        $this->assertTrue($manager->isOwner());
    }

    public function testIsOwnerReturnsFalseForModeratorRole(): void
    {
        $manager = new Manager;
        $manager->role = 'moderator';

        $this->assertFalse($manager->isOwner());
    }
}
