<?php

declare(strict_types=1);

namespace library\explosion;

use block\durability\BlockDurability;
use block\durability\settings\DurabilitySettings;
use factions\manager\FactionManager;
use InvalidArgumentException;
use mob\generators\settings\Settings;
use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Container;
use pocketmine\block\tile\Spawnable;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\ItemIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\particle\HugeExplodeSeedParticle;
use pocketmine\world\Position;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\utils\SubChunkExplorer;
use pocketmine\world\utils\SubChunkExplorerStatus;
use pocketmine\world\World;
use protector\Protector;
use tnt\repulsion\entity\CustomPrimedTNT;
use world\border\utils\Border;
use function ceil;
use function count;
use function floor;
use function min;
use function mt_rand;
use function sqrt;

class FastExplosion
{
	protected float $stepLen = 0.1;
	protected float $deterioration = 2;
	protected float $yield;

	protected float $explosionSize;

	protected int $stackCount = 1;

	protected int $minX;
	protected int $minY;
	protected int $minZ;

	protected int $maxX;
	protected int $maxY;
	protected int $maxZ;

	protected World $world;

	protected SubChunkExplorer $subChunkExplorer;

	private array $affectedBlocks = [];

	public function __construct(private Position $source, private float $size, private Entity|Block|null $entity = null)
	{
		if (!$source->isValid()) {
			throw new InvalidArgumentException('Position does not have a valid world');
		}
		$this->world = $source->getWorld();

		if ($size <= 0) {
			throw new InvalidArgumentException('Explosion radius must be greater than 0, got $size');
		}

		if ($size > 10) {
			$size = $this->size = 10;
		}

		$this->subChunkExplorer = new SubChunkExplorer($this->world);
		$this->yield = min(100, (1 / $size) * 100);
		$this->explosionSize = $size * 2;

		$this->minX = (int)floor($this->source->x - $this->explosionSize - 1);
		$this->minY = (int)floor($this->source->y - $this->explosionSize - 1);
		$this->minZ = (int)floor($this->source->z - $this->explosionSize - 1);
		$this->maxX = (int)ceil($this->source->x + $this->explosionSize + 1);
		$this->maxY = (int)ceil($this->source->y + $this->explosionSize + 1);
		$this->maxZ = (int)ceil($this->source->z + $this->explosionSize + 1);

		if ($this->entity instanceof CustomPrimedTNT) {
			$this->stackCount = $this->entity->getStackCount();
		}
	}

	public function processBlocks(): bool
	{
		if (ExplosionSystem::getExplosionsPerSecond($this->world) > 10) {
			return false;
		}

		if (Server::getInstance()->getTicksPerSecond() < 18) {
			$this->size /= 2;
		}

		if ($this->size < 0.1) {
			return false;
		}

		if (!$this->isBlockBreaking()) {
			return false;
		}

		$border = Border::getInstance();

		$mRays = ($rays = $this->size) - 1;
		$blastForceReduction = $this->stepLen * 0.75;
		$blastDeterioration = ($this->deterioration / 5 + 0.3) * $this->stepLen;

		for ($i = 0; $i < $rays; ++$i) {
			for ($j = 0; $j < $rays; ++$j) {
				for ($k = 0; $k < $rays; ++$k) {
					if ($i === 0 || $i === $mRays || $j === 0 || $j === $mRays || $k === 0 || $k === $mRays) {
						[$shiftX, $shiftY, $shiftZ] = [$i / $mRays * 2 - 1, $j / $mRays * 2 - 1, $k / $mRays * 2 - 1];
						$len = sqrt($shiftX ** 2 + $shiftY ** 2 + $shiftZ ** 2);
						[$shiftX, $shiftY, $shiftZ] = [($shiftX / $len) * $this->stepLen, ($shiftY / $len) * $this->stepLen, ($shiftZ / $len) * $this->stepLen];
						$pointerX = $this->source->x;
						$pointerY = $this->source->y;
						$pointerZ = $this->source->z;

						for ($blastForce = $this->size * (mt_rand(700, 1300) / 1000); $blastForce > 0; $blastForce -= $blastForceReduction) {
							$x = (int)$pointerX;
							$y = (int)$pointerY;
							$z = (int)$pointerZ;

							$vBlockX = $pointerX >= $x ? $x : $x - 1;
							$vBlockY = $pointerY >= $y ? $y : $y - 1;
							$vBlockZ = $pointerZ >= $z ? $z : $z - 1;

							$pointerY += $shiftY;
							$pointerZ += $shiftZ;
							$pointerX += $shiftX;

							if ($this->subChunkExplorer->moveTo($vBlockX, $vBlockY, $vBlockZ) === SubChunkExplorerStatus::INVALID) {
								continue;
							}

							if ($this->subChunkExplorer->currentSubChunk === null) {
								continue;
							}
							$blastForce -= $blastDeterioration;

							if (!isset($this->affectedBlocks[World::blockHash($vBlockX, $vBlockY, $vBlockZ)])) {
								$block = $this->world->getBlockAt($vBlockX, $vBlockY, $vBlockZ, true, false);

								foreach ($block->getAffectedBlocks() as $affectedBlock) {
									if ($affectedBlock->getId() === BlockLegacyIds::AIR) {
										continue;
									}

									$affectedBlockPosition = $affectedBlock->getPosition();
									if ($affectedBlockPosition->getY() >= World::Y_MAX || $affectedBlockPosition->getY() <= World::Y_MIN) {
										continue;
									}
									$blockHash = World::blockHash(
										(int)$affectedBlockPosition->x,
										(int)$affectedBlockPosition->y,
										(int)$affectedBlockPosition->z
									);

									if (!ExplosionSystem::hasBlock($this->world, $blockHash) && !isset($this->affectedBlocks[$blockHash])) {
										if ($border->checkBorderByPosition($affectedBlockPosition)) {
											continue;
										}

										ExplosionSystem::addBlock($this->world, $blockHash);
										$this->affectedBlocks[$blockHash] = $affectedBlock;
									}
								}
							}
						}
					}
				}
			}
		}
		return true;
	}

	public function explode(): bool
	{
		$isRepulsive = $this->entity instanceof CustomPrimedTNT && $this->entity->isRepulsive();

		if ($isRepulsive && !$this->entity->isCancelRepulsion()) {
			$repulsionForce = $this->entity->getRepulsionForce();
			$explosionForce = 1 / ($this->yield / 100);
			$maxDistance = $explosionForce ** 2;
			$repulsionForceY = 1.3;
			$willExplode = 0;

			$repulsionForce += mt_rand(-4, 4) / 10;

			/** @var CustomPrimedTNT $entity */
			foreach ($this->getNearbyEntities(2.0, true) as $entity) {
				if (($this->entity->ticksLived - $entity->ticksLived) > 27 || $entity->isCancelRepulsion() || !$entity->isRepulsive()) {
					continue;
				}

				$entity->setCancelRepulsion();
				$willExplode++;
			}

			$baseForce = $repulsionForce + ($repulsionForce * $willExplode);
			$baseForceY = $repulsionForceY + ($repulsionForceY * $willExplode);

			$lowBaseForceY = ($baseForceY / $repulsionForceY) / 2;

			foreach ($this->getNearbyEntities($explosionForce) as $entity) {
				$entityPosition = $entity->getPosition();
				$distanceSquare = $entityPosition->distanceSquared($this->source);

				if ($distanceSquare > $maxDistance) {
					continue;
				}

				$force = $baseForce / $distanceSquare;
				$forceY = $distanceSquare < 1 ? $baseForceY : $lowBaseForceY;
				$motion = $entityPosition->subtractVector($this->source)->normalize();

				$motion->x *= $baseForce;
				$motion->z *= $baseForce;

				$entity->setMotion($entity->getMotion()->addVector($motion));
			}
		}

		$this->sendBlocks();

		$this->sendDetails(!$isRepulsive);
		$this->onCompletion();
		return true;
	}

	public function getNearbyEntities(?float $expand = null, bool $repulsive = false): array
	{
		$expandSize = 0;
		if ($expand) {
			$expandSize = (int)$expand;
		}

		$minX = ((int)floor($this->minX - 2 - $expandSize)) >> Chunk::COORD_BIT_SIZE;
		$minZ = ((int)floor($this->minZ - 2 - $expandSize)) >> Chunk::COORD_BIT_SIZE;
		$maxX = ((int)floor($this->maxX + 2 + $expandSize)) >> Chunk::COORD_BIT_SIZE;
		$maxZ = ((int)floor($this->maxZ + 2 + $expandSize)) >> Chunk::COORD_BIT_SIZE;

		$entities = [];
		for ($x = $minX; $x <= $maxX; ++$x) {
			for ($z = $minZ; $z <= $maxZ; ++$z) {
				if (!$this->world->isChunkLoaded($x, $z)) {
					continue;
				}

				foreach ($this->world->getChunkEntities($x, $z) as $entity) {
					if (!$entity instanceof CustomPrimedTNT || $entity === $this->entity || !$this->intersectsWith($entity->getBoundingBox())) {
						continue;
					}

					if ($entity->isRepulsive() && !$repulsive) {
						continue;
					}

					$entities[] = $entity;
				}
			}
		}
		return $entities;
	}

	public function intersectsWith(AxisAlignedBB $bb, float $epsilon = 0.00001): bool
	{
		if ($this->maxX - $bb->minX > $epsilon and $bb->maxX - $this->minX > $epsilon) {
			if ($this->maxY - $bb->minY > $epsilon and $bb->maxY - $this->minY > $epsilon) {
				return $this->maxZ - $bb->minZ > $epsilon and $bb->maxZ - $this->minZ > $epsilon;
			}
		}

		return false;
	}

	public function sendBlocks(): void
	{
		$air = VanillaItems::AIR();
		$airBlock = VanillaBlocks::AIR();

		$drops = [];

		$storage = BlockDurability::getInstance()->getDurabilityStorage();
		$minYLayer = DurabilitySettings::getMinWorldYLayer();

		$durabilityReductionByEntity = DurabilitySettings::getDurabilityReductionByEntity($this->entity);
		$durabilityReduction = $durabilityReductionByEntity * $this->getStackCount();

		/** @var Block $block */
		foreach ($this->affectedBlocks as $blockHash => $block) {
			ExplosionSystem::removeBlock($this->world, $blockHash);

			$position = $block->getPosition();
			$maxDurability = DurabilitySettings::getMaxDurabilityOf($block);
			if ($maxDurability !== null && $position->getFloorY() > $minYLayer) {
				$durabilityIndex = $this->world->getFolderName() . ':' . $blockHash;

				$durability = (int)$storage->get($durabilityIndex, $maxDurability);
				$durability -= $durabilityReduction;

				if ($durability > 0) {
					$storage->set($durabilityIndex, $durability);
					continue;
				}

				$storage->remove($durabilityIndex);
			}
			$tile = $this->world->getTile($position);

			if ($tile instanceof Container && $tile instanceof Spawnable) {
				foreach ($tile->getRealInventory()->getContents() as $item) {
					$this->stackDrop(clone $item, $position, $drops);
				}

				if ($tile instanceof Chest) {
					$tile->unpair();
				}
				$tile->close();
			}

			if ($block->getId() === BlockLegacyIds::MONSTER_SPAWNER) {
				if ($this->tryDrop($drop = $block->asItem())) {
					$this->stackDrop(clone $drop, $position, $drops);
				}
			} else {
				foreach ($block->getDrops($air) as $drop) {
					if ($this->tryDrop($drop)) {
						$this->stackDrop(clone $drop, $position, $drops);
					}
				}
			}

			$this->world->setBlockAt($position->x, $position->y, $position->z, $airBlock);
		}
		if (count($drops) > 0) {
			ExplosionSystem::getRegistrant()->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($drops): void {
				foreach ($drops as [$item, $position]) {
					$this->world->dropItem($position, $item);
				}
			}), 1);
		}
	}

	public function tryDrop(Item|Block $item): bool
	{
		if ($item->getId() === ItemIds::MONSTER_SPAWNER) {
			$settings = Settings::get()?->getExplosionSettings();

			if (!$settings->isSpawnerDropEnabled()) {
				return false;
			}

			if ($this->entity instanceof CustomPrimedTNT) {
				$chance = 30;
			} else {
				$chance = 50;
			}

			if (mt_rand(0, 10000) > (round($chance, 2, PHP_ROUND_HALF_DOWN) * 100)) {
				return false;
			}

			return true;
		}
		return true;
	}

	public function stackDrop(Item $item, Position $position, &$drops): void
	{
		$index = $item->__toString();
		$drop = $drops[$index] ?? null;

		if (!$drop) {
			$drop = [$item, $position];
		} else {
			/** @var Item $stackedDrop */
			$stackedDrop = $drop[0];

			if ($stackedDrop instanceof Item && !$item->isNull()) {
				$stackedDrop->setCount($stackedDrop->getCount() + $item->getCount());
			}
		}

		$drops[$index] = $drop;
	}

	public function getStackCount(): int
	{
		return $this->stackCount;
	}

	public function sendDetails(bool $particle = true): void
	{
		$this->world->addSound($this->source, new ExplodeSound);

		if ($particle) {
			$this->world->addParticle($this->source, new HugeExplodeSeedParticle);
		}
	}

	public function onCompletion(): void
	{
		FactionManager::getInstance()->getFactionFrom($this->source)?->addAttack($this->source);
	}

	public function getSource(): Position
	{
		return $this->source;
	}

	public function getEntity(): ?Entity
	{
		return $this->entity;
	}

	public function isBlockBreaking(): bool
	{
		if ($this->entity instanceof CustomPrimedTNT && !$this->entity->isBlockBreaking()) {
			return false;
		}

		if ($this->entity->isUnderwater() && !DurabilitySettings::canExplodeBlocksInsideWater($this->entity)) {
			return false;
		}

		if (!Protector::getInstance()?->canByPosition($this->getSource(), 'edit')) {
			return false;
		}
		return true;
	}
}