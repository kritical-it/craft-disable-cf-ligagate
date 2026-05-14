<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\jobs;

use Craft;
use craft\queue\BaseJob;
use KriticalIT\Ligagate\Plugin;

class CheckProxyStatusJob extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->proxy->check();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('disable-cf-ligagate', 'Checking Cloudflare proxy status');
    }
}
