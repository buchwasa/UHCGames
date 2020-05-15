<?php
declare(strict_types=1);

namespace uhcgames\game;

use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use uhcgames\game\type\GamePhase;
use uhcgames\game\type\GameTimer;
use uhcgames\Loader;

class UHCGamesTask extends Task{
	/** @var int */
	private $countdown = GameTimer::TIMER_COUNTDOWN;
	/** @var int */
	private $meetupTimer = GameTimer::TIMER_MEETUP;
	/** @var int */
	private $shutdownTimer = GameTimer::TIMER_SHUTDOWN;

	/** @var int */
	private $border;

	/** @var Loader */
	private $plugin;
	/** @var Server */
	private $server;

	public function __construct(Loader $plugin){
		$this->plugin = $plugin;
		$this->server = $plugin->getServer();
		$this->border = $plugin->getConfig()->get($plugin->map->getFolderName())["border"];
	}

	public function onRun(int $currentTick){
		switch($this->plugin->gameStatus){
			case GamePhase::PHASE_WAITING:
				if(count($this->plugin->gamePlayers) >= 2){
					$this->plugin->gameStatus = GamePhase::PHASE_COUNTDOWN;
				}
				break;
			case GamePhase::PHASE_COUNTDOWN:
				$this->handleCountdown();
				break;
			case GamePhase::PHASE_MATCH:
				if(count($this->plugin->gamePlayers) <= 4){
					$this->plugin->gameStatus = GamePhase::PHASE_MEETUP;
				}
				break;
			case GamePhase::PHASE_MEETUP:
				$this->handleMeetup();
				break;
		}

		if($this->plugin->gameStatus >= GamePhase::PHASE_MATCH){
			foreach($this->plugin->gamePlayers as $player){
				$player->setImmobile(false);
			}
			$this->onWin();
		}

		foreach($this->plugin->gamePlayers as $player){
			$this->handleBorder($player);
			$player->setScoreTag(floor($player->getHealth() / 2) . TextFormat::RED . "❤");
		}
	}

	private function handleCountdown(){
		if(count($this->plugin->gamePlayers) <= 1){
			$this->plugin->gameStatus = GamePhase::PHASE_WAITING;
			$this->countdown = GameTimer::TIMER_COUNTDOWN;
		}

		switch($this->countdown){
			case 60:
				$this->server->broadcastMessage(Loader::PREFIX . "Game is starting in 1 minute.");
				break;
			case 30:
			case 10:
				$this->server->broadcastMessage(Loader::PREFIX . "Game is starting in $this->countdown seconds.");
				break;
			case 5:
			case 4:
			case 3:
			case 2:
			case 1:
				$this->server->broadcastMessage(Loader::PREFIX . "Game is starting in $this->countdown second(s).");
				break;
			case 0:
				$this->plugin->gameStatus = GamePhase::PHASE_MATCH;

				foreach($this->plugin->gamePlayers as $player){
					$player->setHealth($player->getMaxHealth());
					$player->getHungerManager()->setFood($player->getHungerManager()->getMaxFood());

					$armorInventory = $player->getArmorInventory();
					$inventory = $player->getInventory();
					$inventory->addItem(VanillaItems::STONE_SWORD());
					$armorInventory->setHelmet(VanillaItems::IRON_HELMET());
					$armorInventory->setChestplate(VanillaItems::IRON_CHESTPLATE());
					$armorInventory->setLeggings(VanillaItems::IRON_CHESTPLATE());
					$armorInventory->setBoots(VanillaItems::IRON_BOOTS());

					$player->setImmobile(false);
				}
				break;
		}

		$this->countdown--;
	}

	private function handleMeetup(){
		switch($this->meetupTimer){
			case 30:
			case 10:
				$this->server->broadcastMessage(Loader::PREFIX . "Meetup is starting in $this->meetupTimer seconds.");
				break;
			case 5:
			case 4:
			case 3:
			case 2:
			case 1:
				$this->server->broadcastMessage(Loader::PREFIX . "Meetup is starting in $this->meetupTimer second(s).");
				break;
			case 0:
				$spawns = $this->plugin->getConfig()->get($this->plugin->map->getFolderName())["meetup-spawns"];
				shuffle($spawns);
				foreach($this->plugin->gamePlayers as $player){
					$locations = array_shift($spawns);
					if(isset($locations)){
						$player->teleport(new Vector3($locations[0], $locations[1], $locations[2]));
					}
				}

				$this->server->broadcastMessage(Loader::PREFIX . "Border shrunk to $this->border! Walking into it will cause you to take damage!");
				$this->plugin->gameStatus = GamePhase::PHASE_BORDER;
				break;
		}

		$this->meetupTimer--;
	}

	private function onWin(){
		if(count($this->plugin->gamePlayers) > 1) return;

		if($this->shutdownTimer === 0){
			foreach($this->server->getOnlinePlayers() as $p){
				$config = $this->plugin->getConfig();
				$p->transfer((string) $config->get("server-ip"), (int) $config->get("server-port"));
			}
			$this->plugin->getServer()->shutdown();
		}elseif($this->shutdownTimer === 5){
			if(count($this->plugin->gamePlayers) === 1){
				foreach($this->plugin->gamePlayers as $player){
					$player->setGamemode(GameMode::CREATIVE());
					$this->server->broadcastMessage(Loader::PREFIX . $player->getName() . " won the game!");
				}
			}else{
				$this->server->broadcastMessage(Loader::PREFIX . "No one won the game.");
			}
		}

		$this->shutdownTimer--;
	}

	private function handleBorder(Player $p){
		$spawn = $this->plugin->map->getSpawnLocation();
		if($this->plugin->gameStatus === GamePhase::PHASE_BORDER){
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
}
