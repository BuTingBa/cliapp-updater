<?php

declare(strict_types=1);

namespace BuTing\CliAppUpdater;

/** Internal signal: no application file has changed; retry with the verified full package. */
final class DeltaUnavailable extends \RuntimeException
{
}
