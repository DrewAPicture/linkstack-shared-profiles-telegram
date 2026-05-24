<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Events;

class PendingLinkSubmitted
{
    public function __construct(
        public readonly int $profileId,
        public readonly int $linkId,
        public readonly string $link,
        public readonly string $title,
    ) {}
}
