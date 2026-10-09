<?php

namespace justinholtweb\leads\enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    /** Double opt-in: waiting for the visitor to click the link. Nothing is sent anywhere yet. */
    case Unconfirmed = 'unconfirmed';
    /** The popup has no integration, so there is nowhere to sync to. */
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Synced => 'Synced',
            self::Failed => 'Failed',
            self::Unconfirmed => 'Awaiting confirmation',
            self::None => 'Not synced',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'orange',
            self::Synced => 'green',
            self::Failed => 'red',
            self::Unconfirmed => 'blue',
            self::None => 'gray',
        };
    }
}
