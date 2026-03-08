<?php

namespace justinholtweb\leads\events;

use justinholtweb\leads\elements\Popup;
use yii\base\Event;

class PopupEvent extends Event
{
    public Popup $popup;
    public bool $isNew = false;
}
