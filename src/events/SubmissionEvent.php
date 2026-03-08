<?php

namespace justinholtweb\leads\events;

use justinholtweb\leads\records\SubmissionRecord;
use yii\base\Event;

class SubmissionEvent extends Event
{
    public SubmissionRecord $submission;
}
