<?php

declare(strict_types=1);

namespace library;

use library\explosion\ExplosionSystem;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;

class Loader extends PluginBase
{
	use SingletonTrait;

	protected function onLoad() : void
	{
		self::setInstance($this);

		Await::init($this);
	}

	protected function onEnable() : void
	{
		if (!ExplosionSystem::isRegistered()) {
			ExplosionSystem::register($this);
		}
	}
}
