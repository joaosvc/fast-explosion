<?php

declare(strict_types=1);

namespace library\explosion;

use InvalidArgumentException;
use LogicException;
use pocketmine\plugin\Plugin;
use pocketmine\world\World;
use function microtime;

final class ExplosionSystem
{
	private static ?Plugin $registrant = null;

	public static array $blocks = [];
	public static array $explosions = [];

	public static function register(Plugin $plugin) : void
	{
		if (self::isRegistered()) {
			throw new InvalidArgumentException($plugin->getName() . ' attempted to register ' . self::class . ' twice.');
		}

		self::$registrant = $plugin;
	}

	public static function isRegistered() : bool
	{
		return self::$registrant instanceof Plugin;
	}

	public static function getRegistrant() : Plugin
	{
		return self::$registrant ?? throw new LogicException('Cannot obtain registrant before registration');
	}

	public static function addBlock(World $world, $blockHash) : void
	{
		self::$blocks[$world->getId()][$blockHash] = true;
	}

	public static function hasBlock(World $world, $blockHash) : bool
	{
		return isset(self::$blocks[$world->getId()][$blockHash]);
	}

	public static function removeBlock(World $world, $blockHash) : void
	{
		unset(self::$blocks[$world->getId()][$blockHash]);
	}

	public static function getExplosionsPerSecond(World $world) : int
	{
		$default = ['amount' => 0, 'time' => microtime(true)];
		$explosions = self::$explosions[$world->getId()] ?? $default;
		$timemt = microtime(true) - $explosions['time'];

		if ($timemt > 1) {
			$explosions = $default;
		}

		$amount = $explosions['amount'];

		$explosions['amount']++;
		self::$explosions[$world->getId()] = $explosions;

		return $amount;
	}
}
