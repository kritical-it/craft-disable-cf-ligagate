<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\contracts;

use KriticalIT\Ligagate\models\Settings;

interface StatusResolverInterface
{
    public function shouldDisableProxy(Settings $settings): bool;
}
