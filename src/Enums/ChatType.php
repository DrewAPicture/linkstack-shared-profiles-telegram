<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Providers\Telegram\Enums;

enum ChatType: string
{
    case PrivateChat = 'private';
    case Group = 'group';
    case SuperGroup = 'supergroup';
    case Channel = 'channel';
}
