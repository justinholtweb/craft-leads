<?php

namespace justinholtweb\leads\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class PopupQuery extends ElementQuery
{
    public ?string $popupType = null;
    public ?string $popupStatus = null;
    public ?string $triggerType = null;
    public ?string $integrationProvider = null;
    public ?int $priority = null;

    public function popupType(?string $value): self
    {
        $this->popupType = $value;
        return $this;
    }

    public function popupStatus(?string $value): self
    {
        $this->popupStatus = $value;
        return $this;
    }

    public function triggerType(?string $value): self
    {
        $this->triggerType = $value;
        return $this;
    }

    public function integrationProvider(?string $value): self
    {
        $this->integrationProvider = $value;
        return $this;
    }

    public function priority(?int $value): self
    {
        $this->priority = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('leads_popups');

        $this->query->select([
            'leads_popups.popupType',
            'leads_popups.triggerType',
            'leads_popups.triggerValue',
            'leads_popups.templateKey',
            'leads_popups.heading',
            'leads_popups.bodyText',
            'leads_popups.buttonText',
            'leads_popups.buttonColor',
            'leads_popups.backgroundColor',
            'leads_popups.backgroundImage',
            'leads_popups.customCss',
            'leads_popups.formFields',
            'leads_popups.targetingRules',
            'leads_popups.integrationProvider',
            'leads_popups.integrationSettings',
            'leads_popups.position',
            'leads_popups.popupStatus',
            'leads_popups.priority',
        ]);

        if ($this->popupType !== null) {
            $this->subQuery->andWhere(Db::parseParam('leads_popups.popupType', $this->popupType));
        }

        if ($this->popupStatus !== null) {
            $this->subQuery->andWhere(Db::parseParam('leads_popups.popupStatus', $this->popupStatus));
        }

        if ($this->triggerType !== null) {
            $this->subQuery->andWhere(Db::parseParam('leads_popups.triggerType', $this->triggerType));
        }

        if ($this->integrationProvider !== null) {
            $this->subQuery->andWhere(Db::parseParam('leads_popups.integrationProvider', $this->integrationProvider));
        }

        if ($this->priority !== null) {
            $this->subQuery->andWhere(Db::parseParam('leads_popups.priority', $this->priority));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'draft' => ['leads_popups.popupStatus' => 'draft'],
            'active' => ['leads_popups.popupStatus' => 'active'],
            'paused' => ['leads_popups.popupStatus' => 'paused'],
            'archived' => ['leads_popups.popupStatus' => 'archived'],
            default => parent::statusCondition($status),
        };
    }
}
