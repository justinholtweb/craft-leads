<?php

namespace justinholtweb\leads\enums;

enum PopupType: string
{
    case Modal = 'modal';
    case SlideIn = 'slidein';
    case Bar = 'bar';
    case Inline = 'inline';

    public function label(): string
    {
        return match ($this) {
            self::Modal => 'Modal',
            self::SlideIn => 'Slide-in',
            self::Bar => 'Notification Bar',
            self::Inline => 'Inline',
        };
    }
}
