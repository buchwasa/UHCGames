<?php
declare(strict_types=1);

namespace uhcgames;

use uhcgames\utils\RegionUtils;
use pocketmine\block\Block;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Chest;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\World;
use uhcgames\item\GoldenHead;
use uhcgames\tasks\UHCGamesTask;

class Loader extends PluginBase{
	/** @var Player[] */
	public $gamePlayers = [];
	public $usedSpawns = [];
	/** @var int */
	public $gameStatus = UHCGamesTask::WAITING;
	/** @var World */
	public $map;
	/** @var string */
	public const PREFIX = TF::RED . TF::BOLD . "Adrenaline> " . TF::RESET . TF::GOLD;

	public function onEnable(){
		$this->map = $this->getServer()->getWorldManager()->getDefaultWorld();
		if(!$this->getConfig()->get($this->map->getFolderName())){
			$this->getLogger()->emergency("Map not found in configuration, shutting down!");
			$this->getServer()->shutdown();
		}
		$this->map->setTime(7000);
		$this->map->stopTime();
		new EventListener($this);

		$this->getScheduler()->scheduleRepeatingTask(new UHCGamesTask($this), 20);

		ItemFactory::register(new GoldenHead(ItemIds::GOLDEN_APPLE, 1, "Golden Head"), true);
	}

	public function randomizeSpawn(Player $player){
		$spawns = $this->getConfig()->get($this->map->getFolderName())["spawnpoints"];
		shuffle($spawns);
		$locations = array_shift($spawns);
		RegionUtils::onChunkGenerated($this->map, $locations[0] >> 4, $locations[2] >> 4, function() use($locations, $player){
			if(!in_array($locations, $this->usedSpawns)){
				$player->teleport(new Vector3($locations[0], $locations[1], $locations[2]));
				$this->usedSpawns[$player->getName()] = $locations;
			}else{
				$this->randomizeSpawn($player);
			}
		});
	}

	public function fillChest(Chest $chest){
		$inventory = $chest->getInventory();
		$inventory->clearAll();
		foreach($this->getConfig()->get("items") as $item){
			if(mt_rand(1, 100) <= 50){
				$itemString = ItemFactory::fromString($item);
				$count = 1;
				$meta = 0;
				$data = explode(":", $item);
				if(count($data) > 2){
					$count = mt_rand(1, (int) $data[2]);
					$meta = (int) $data[1];
				}
				$item = ItemFactory::get($itemString->getId(), $meta, $count);

				$rand = mt_rand(0, 26);
				$inventory->setItem($rand, $item);
			}
		}
	}
}
