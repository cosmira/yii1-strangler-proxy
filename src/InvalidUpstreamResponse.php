<?php

declare(strict_types=1);

namespace Cosmira\Strangler;

use UnexpectedValueException;

/** A decoded upstream value does not match the requested mapping. */
final class InvalidUpstreamResponse extends UnexpectedValueException {}
