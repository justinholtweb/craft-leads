<?php

namespace justinholtweb\leads\enums;

enum PopupPosition: string
{
    case Top = 'top';
    case Bottom = 'bottom';
    case BottomRight = 'bottom-right';
    case BottomLeft = 'bottom-left';

    public function label(): string
    {
        return match ($this) {
            self::Top => 'Top',
            self::Bottom => 'Bottom',
            self::BottomRight => 'Bottom Right',
            self::BottomLeft => 'Bottom Left',
        };
    }
}
