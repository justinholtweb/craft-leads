<?php

namespace justinholtweb\leads\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->css = [
            'css/leads-cp.css',
        ];

        $this->js = [
            'js/leads-cp.js',
        ];

        parent::init();
    }
}
