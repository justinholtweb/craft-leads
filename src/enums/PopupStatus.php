<?php

namespace justinholtweb\leads\enums;

enum PopupStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'white',
            self::Active => 'green',
            self::Paused => 'orange',
            self::Archived => 'red',
        };
    }
}
