<?php

namespace justinholtweb\leads\enums;

enum TriggerType: string
{
    case Time = 'time';
    case Scroll = 'scroll';
    case Exit = 'exit';
    case Click = 'click';

    public function label(): string
    {
        return match ($this) {
            self::Time => 'Time Delay',
            self::Scroll => 'Scroll Percentage',
            self::Exit => 'Exit Intent',
            self::Click => 'Click',
        };
    }
}
