<?php

declare(strict_types=1);

namespace Milpa\DevTools\Support;

/** Thrown when {@see RootResolver} cannot honestly resolve the host application's root directory. */
final class RootNotFoundException extends \RuntimeException
{
}
