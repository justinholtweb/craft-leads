<?php

namespace justinholtweb\leads\enums;

enum TriggerType: string
{
    case Time = 'time';
    case Scroll = 'scroll';
    case Exit = 'exit';
    case Click = 'click';
    case Idle = 'idle';
    case View = 'view';

    public function label(): string
    {
        return match ($this) {
            self::Time => 'Time Delay',
            self::Scroll => 'Scroll Percentage',
            self::Exit => 'Exit Intent',
            self::Click => 'Click',
            self::Idle => 'Idle',
            self::View => 'Element in View',
        };
    }
}
