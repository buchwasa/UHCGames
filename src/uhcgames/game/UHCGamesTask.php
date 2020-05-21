<?php
declare(strict_types=1);

namespace uhcgames\game;

use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use uhcgames\game\type\GamePhase;
use uhcgames\game\type\GameTimer;
use uhcgames\Loader;
use wumpotamus\chunkloader\ChunkRegion;
use function array_shift;
use function count;
use function floor;
use function shuffle;

class UHCGamesTask extends Task{
	/** @var int */
	private $countdown = GameTimer::TIMER_COUNTDOWN;
	/** @var int */
	private $meetupTimer = GameTimer::TIMER_MEETUP;
	/** @var int */
	private $shutdownTimer = GameTimer::TIMER_SHUTDOWN;
	/** @var int */
	private $gamePhase = GamePhase::PHASE_WAITING;

	/** @var int */
	private $border;
	/** @var Loader */
	private $plugin;
	/** @var World */
	private $world;

	public function __construct(Loader $plugin, World $world){
		$this->plugin = $plugin;
		$this->world = $world;
		$this->border = $plugin->getConfig()->get("worlds")[$world->getFolderName()]["border"];
	}

	public function getGamePhase() : int{
		return $this->gamePhase;
	}

	public function onRun(int $currentTick){
		switch($this->gamePhase){
			case GamePhase::PHASE_WAITING:
				if(count($this->plugin->getGamePlayers()) >= 2){
					$this->gamePhase = GamePhase::PHASE_COUNTDOWN;
				}
				break;
			case GamePhase::PHASE_COUNTDOWN:
				$this->handleCountdown();
				break;
			case GamePhase::PHASE_MATCH:
				if(count($this->plugin->getGamePlayers()) <= 4){
					$this->gamePhase = GamePhase::PHASE_MEETUP;
				}
				break;
			case GamePhase::PHASE_MEETUP:
				$this->handleMeetup();
				break;
		}

		if($this->gamePhase >= GamePhase::PHASE_MATCH){
			foreach($this->plugin->getGamePlayers() as $player){
				$player->setImmobile(false); //?
			}
			if(count($this->plugin->getGamePlayers()) <= 1){
				$this->handleWin();
			}
		}

		foreach($this->plugin->getGamePlayers() as $player){
			$this->handleBorder($player);
			$player->setScoreTag(floor($player->getHealth() / 2) . TextFormat::RED . "❤");
		}
	}

	private function handleCountdown(){
		$server = $this->plugin->getServer();
		if(count($this->plugin->getGamePlayers()) <= 1){
			$this->gamePhase = GamePhase::PHASE_WAITING;
			$this->countdown = GameTimer::TIMER_COUNTDOWN;
		}

		switch($this->countdown){
			case 60:
				$server->broadcastMessage(Loader::getPrefix() . "Game is starting in 1 minute.");
				break;
			case 30:
			case 10:
				$server->broadcastMessage(Loader::getPrefix() . "Game is starting in $this->countdown seconds.");
				break;
			case 5:
			case 4:
			case 3:
			case 2:
			case 1:
				$server->broadcastMessage(Loader::getPrefix() . "Game is starting in $this->countdown second(s).");
				break;
			case 0:
				foreach($this->plugin->getGamePlayers() as $player){
					$player->setHealth($player->getMaxHealth());
					$player->getHungerManager()->setFood($player->getHungerManager()->getMaxFood());

					$armorInventory = $player->getArmorInventory();
					$inventory = $player->getInventory();
					$inventory->addItem(VanillaItems::STONE_SWORD());
					$armorInventory->setHelmet(VanillaItems::IRON_HELMET());
					$armorInventory->setChestplate(VanillaItems::IRON_CHESTPLATE());
					$armorInventory->setLeggings(VanillaItems::IRON_LEGGINGS());
					$armorInventory->setBoots(VanillaItems::IRON_BOOTS());

					$player->setImmobile(false);
				}
				$this->gamePhase = GamePhase::PHASE_MATCH;
				break;
		}

		$this->countdown--;
	}

	private function handleMeetup(){
		$server = $this->plugin->getServer();
		switch($this->meetupTimer){
			case 30:
			case 10:
				$server->broadcastMessage(Loader::getPrefix() . "Meetup is starting in $this->meetupTimer seconds.");
				break;
			case 5:
			case 4:
			case 3:
			case 2:
			case 1:
				$server->broadcastMessage(Loader::getPrefix() . "Meetup is starting in $this->meetupTimer second(s).");
				break;
			case 0:
				$spawns = $this->plugin->getConfig()->get("worlds")[$this->world->getFolderName()]["meetup-spawns"];
				shuffle($spawns);
				foreach($this->plugin->getGamePlayers() as $player){
					$locations = array_shift($spawns);
					if(isset($locations)){
						$player->teleport(new Vector3($locations[0], $locations[1], $locations[2]));
					}
				}

				$this->buildBorder();
				$server->broadcastMessage(Loader::getPrefix() . "Border shrunk to $this->border! Walking into it will cause you to take damage!");
				$this->gamePhase = GamePhase::PHASE_BORDER;
				break;
		}

		$this->meetupTimer--;
	}

	private function handleWin(){
		$server = $this->plugin->getServer();
		if($this->shutdownTimer === 5){
			if(count($this->plugin->getGamePlayers()) === 1){
				foreach($this->plugin->getGamePlayers() as $player){
					$player->setGamemode(GameMode::CREATIVE());
					$server->broadcastMessage(Loader::getPrefix() . $player->getName() . " won the game!");
				}
			}else{
				$server->broadcastMessage(Loader::getPrefix() . "No one won the game.");
			}
		}elseif($this->shutdownTimer === 0){
			$config = $this->plugin->getConfig();
			if((bool) $config->get("transfer")){
				foreach($server->getOnlinePlayers() as $p){
					$p->transfer((string) $config->get("server-ip"), (int) $config->get("server-port"));
				}
			}
			$this->plugin->getServer()->shutdown();
		}

		$this->shutdownTimer--;
	}

	private function handleBorder(Player $p){
		$spawn = $this->world->getSpawnLocation();
		if($this->gamePhase === GamePhase::PHASE_BORDER){
			if((
				 $p->getPosition()->getX() > $spawn->getX() + $this->border
				 || $p->getPosition()->getX() < $spawn->getX() - $this->border ||
				$p->getPosition()->getZ() > $spawn->getZ() + $this->border
				|| $p->getPosition()->getZ() < $spawn->getZ() - $this->border
			)){
				$p->attack(new EntityDamageEvent($p, EntityDamageEvent::CAUSE_CUSTOM, 2));
				$p->sendTip("You are outside border, get back inside!");
			}
		}
	}

	private function buildBorder(){
		$safeX = $this->world->getSafeSpawn()->getX();
		$safeZ = $this->world->getSafeSpawn()->getZ();
		for($minX = $safeX - $this->border; $minX <= $safeX + $this->border; $minX++){
			ChunkRegion::onChunkGenerated($this->world, $minX >> 4, $safeZ + $this->border >> 4, function() use ($minX, $safeX, $safeZ){
				$highestBlock = $this->world->getHighestBlockAt($minX, $safeZ + $this->border);
				for($y = $highestBlock; $y <= $highestBlock + 4; $y++){
					$this->world->setBlock(new Vector3($minX, $y, $safeZ + $this->border), VanillaBlocks::BEDROCK());
				}
			});

			ChunkRegion::onChunkGenerated($this->world, $minX >> 4, $safeZ - $this->border >> 4, function() use ($minX, $safeX, $safeZ){
				$highestBlock = $this->world->getHighestBlockAt($minX, $safeZ - $this->border);
				for($y = $highestBlock; $y <= $highestBlock + 4; $y++){
					$this->world->setBlock(new Vector3($minX, $y, $safeZ - $this->border), VanillaBlocks::BEDROCK());
				}
			});
		}

		for($minZ = $safeZ - $this->border; $minZ <= $safeZ + $this->border; $minZ++){
			ChunkRegion::onChunkGenerated($this->world, $safeX + $this->border >> 4, $minZ >> 4, function() use ($minZ, $safeX, $safeZ){
				$highestBlock = $this->world->getHighestBlockAt($safeX + $this->border, $minZ);
				for($y = $highestBlock; $y <= $highestBlock + 4; $y++){
					$this->world->setBlock(new Vector3($safeX + $this->border, $y, $minZ), VanillaBlocks::BEDROCK());
				}
			});

			ChunkRegion::onChunkGenerated($this->world, $safeX - $this->border >> 4, $minZ >> 4, function() use ($minZ, $safeX, $safeZ){
				$highestBlock = $this->world->getHighestBlockAt($safeX - $this->border, $minZ);
				for($y = $highestBlock; $y <= $highestBlock + 4; $y++){
					$this->world->setBlock(new Vector3($safeX - $this->border, $y, $minZ), VanillaBlocks::BEDROCK());
				}
			});
		}
	}
}
