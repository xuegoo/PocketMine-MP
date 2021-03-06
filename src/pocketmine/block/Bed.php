<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\lang\TranslationContainer;
use pocketmine\level\Level;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Bearing;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\tile\Bed as TileBed;
use pocketmine\tile\Tile;
use pocketmine\utils\TextFormat;

class Bed extends Transparent{
	public const BITFLAG_OCCUPIED = 0x04;
	public const BITFLAG_HEAD = 0x08;

	protected $id = self::BED_BLOCK;

	protected $itemId = Item::BED;

	public function __construct(int $meta = 0){
		$this->setDamage($meta);
	}

	public function getHardness() : float{
		return 0.2;
	}

	public function getName() : string{
		return "Bed Block";
	}

	protected function recalculateBoundingBox() : ?AxisAlignedBB{
		return new AxisAlignedBB(0, 0, 0, 1, 0.5625, 1);
	}

	public function isHeadPart() : bool{
		return ($this->meta & self::BITFLAG_HEAD) !== 0;
	}

	/**
	 * @return bool
	 */
	public function isOccupied() : bool{
		return ($this->meta & self::BITFLAG_OCCUPIED) !== 0;
	}

	public function setOccupied(bool $occupied = true){
		if($occupied){
			$this->meta |= self::BITFLAG_OCCUPIED;
		}else{
			$this->meta &= ~self::BITFLAG_OCCUPIED;
		}

		$this->getLevel()->setBlock($this, $this, false, false);

		if(($other = $this->getOtherHalf()) !== null and $other->isOccupied() !== $occupied){
			$other->setOccupied($occupied);
		}
	}

	/**
	 * @param int  $meta
	 * @param bool $isHead
	 *
	 * @return int
	 */
	public static function getOtherHalfSide(int $meta, bool $isHead = false) : int{
		$side = Bearing::toFacing($meta & 0x03);
		if($isHead){
			$side = Facing::opposite($side);
		}

		return $side;
	}

	/**
	 * @return Bed|null
	 */
	public function getOtherHalf() : ?Bed{
		$other = $this->getSide(self::getOtherHalfSide($this->meta, $this->isHeadPart()));
		if($other instanceof Bed and $other->getId() === $this->getId() and $other->isHeadPart() !== $this->isHeadPart() and (($other->getDamage() & 0x03) === ($this->getDamage() & 0x03))){
			return $other;
		}

		return null;
	}

	public function onActivate(Item $item, Player $player = null) : bool{
		if($player !== null){
			$other = $this->getOtherHalf();
			if($other === null){
				$player->sendMessage(TextFormat::GRAY . "This bed is incomplete");

				return true;
			}elseif($player->distanceSquared($this) > 4 and $player->distanceSquared($other) > 4){
				$player->sendMessage(new TranslationContainer(TextFormat::GRAY . "%tile.bed.tooFar"));
				return true;
			}

			$time = $this->getLevel()->getTime() % Level::TIME_FULL;

			$isNight = ($time >= Level::TIME_NIGHT and $time < Level::TIME_SUNRISE);

			if(!$isNight){
				$player->sendMessage(new TranslationContainer(TextFormat::GRAY . "%tile.bed.noSleep"));

				return true;
			}

			$b = ($this->isHeadPart() ? $this : $other);

			if($b->isOccupied()){
				$player->sendMessage(new TranslationContainer(TextFormat::GRAY . "%tile.bed.occupied"));

				return true;
			}

			$player->sleepOn($b);
		}

		return true;

	}

	public function place(Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, Player $player = null) : bool{
		$down = $this->getSide(Facing::DOWN);
		if(!$down->isTransparent()){
			$this->meta = $player instanceof Player ? $player->getDirection() : 0;
			$next = $this->getSide(self::getOtherHalfSide($this->meta));
			if($next->canBeReplaced() and !$next->getSide(Facing::DOWN)->isTransparent()){
				parent::place($item, $blockReplace, $blockClicked, $face, $clickVector, $player);
				$this->getLevel()->setBlock($next, BlockFactory::get($this->id, $this->meta | self::BITFLAG_HEAD), true, true);

				Tile::createTile(Tile::BED, $this->getLevel(), TileBed::createNBT($this, $face, $item, $player));
				Tile::createTile(Tile::BED, $this->getLevel(), TileBed::createNBT($next, $face, $item, $player));

				return true;
			}
		}

		return false;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		if($this->isHeadPart()){
			return [$this->getItem()];
		}

		return [];
	}

	public function getPickedItem() : Item{
		return $this->getItem();
	}

	private function getItem() : Item{
		$tile = $this->getLevel()->getTile($this);
		if($tile instanceof TileBed){
			return ItemFactory::get($this->getItemId(), $tile->getColor());
		}

		return ItemFactory::get($this->getItemId(), 14); //Red
	}

	public function isAffectedBySilkTouch() : bool{
		return false;
	}

	public function getAffectedBlocks() : array{
		if(($other = $this->getOtherHalf()) !== null){
			return [$this, $other];
		}

		return parent::getAffectedBlocks();
	}
}
