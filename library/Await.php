<?php

declare(strict_types=1);

namespace library;

use LogicException;
use SOFe\AwaitStd\AwaitStd;

final class Await
{
	private static ?AwaitStd $awaitStd = null;

	public static function init(Loader $plugin) : void
	{
		self::$awaitStd = AwaitStd::init($plugin);
	}

	public static function getAwaitStd() : AwaitStd
	{
		return self::$awaitStd ?? throw new LogicException('Cannot obtain awaitStd before registration');
	}
}
