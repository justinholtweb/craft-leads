<?php

namespace justinholtweb\leads\web\assets\frontend;

use craft\web\AssetBundle;

class FrontendAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->css = [
            'css/leads.css',
        ];

        $this->js = [
            'js/leads.js',
        ];

        parent::init();
    }
}
