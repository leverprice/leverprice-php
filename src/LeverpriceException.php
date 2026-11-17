<?php

declare(strict_types=1);

namespace Leverprice;

/** Every error the SDK reports through `onError` (it never throws them into your code). */
class LeverpriceException extends \RuntimeException {}
