<?php
declare(strict_types=1);

namespace uhcgames;

use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\block\tile\Chest;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use uhcgames\tasks\UHCGamesTask;

class EventListener implements Listener{
	/** @var Loader */
	private $plugin;
	/** @var array */
	private $placedBlocks = [];

	public function __construct(Loader $plugin){
		$this->plugin = $plugin;
		$plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
	}

	public function handleSend(DataPacketSendEvent $ev){
		foreach($ev->getPackets() as $packet){
			if($packet instanceof AdventureSettingsPacket){
				$packet->setFlag(AdventureSettingsPacket::NO_CLIP, false);
			}
		}
	}

	public function handleLogin(PlayerLoginEvent $ev){
		$player = $ev->getPlayer();
		if($this->plugin->gameStatus <= UHCGamesTask::COUNTDOWN){
			$this->plugin->gamePlayers[$player->getName()] = $player;
		}else{
			$player->disconnect("This game has already started!");
		}
	}

	public function handleJoin(PlayerJoinEvent $ev){
		$player = $ev->getPlayer();
		$ev->setJoinMessage("");

		$this->plugin->randomizeSpawn($player);

		$player->setImmobile();
	}

	public function handleDamage(EntityDamageEvent $ev){
		if($this->plugin->gameStatus <= UHCGamesTask::COUNTDOWN){
			$ev->setCancelled();
		}
	}

	public function handleLeave(PlayerQuitEvent $ev){
		$player = $ev->getPlayer();
		$ev->setQuitMessage("");
		if(isset($this->plugin->gamePlayers[$player->getName()])){
			unset($this->plugin->gamePlayers[$player->getName()]);
		}elseif(isset($this->plugin->usedSpawns[$player->getName()])){
			unset($this->plugin->usedSpawns[$player->getName()]);
		}
	}

	public function handleDeath(PlayerDeathEvent $ev){
		$player = $ev->getPlayer();
		$player->getWorld()->dropItem($player->getPosition(), VanillaItems::GOLDEN_APPLE());
		$player->setGamemode(GameMode::SPECTATOR());
		if(isset($this->plugin->gamePlayers[$player->getName()])){
			unset($this->plugin->gamePlayers[$player->getName()]);
		}

		$cause = $player->getLastDamageCause();
		if($cause instanceof EntityDamageByEntityEvent || $cause instanceof EntityDamageByChildEntityEvent){
			$killer = $cause->getDamager();
			if($killer instanceof Player){
				$killer->sendTip(TextFormat::RED . "Eliminated " . $player->getDisplayName());
			}
		}
	}

	public function handlePlace(BlockPlaceEvent $ev){
		$block = $ev->getBlock();
		$player = $ev->getPlayer();
		$placeable = false;
		foreach($this->plugin->getConfig()->get("placeable-blocks") as $b){
			if($block->getId() === VanillaBlocks::fromString($b)->getId()){
				$placeable = true;
				break;
			}
		}
		if(!$placeable){
			$ev->setCancelled();
		}else{
			$this->placedBlocks[World::blockHash($block->getPos()->getX(), $block->getPos()->getY(), $block->getPos()->getZ())] = $player->getName();
		}
	}

	public function handleBreak(BlockBreakEvent $ev){
		$block = $ev->getBlock();

		$blockHash = World::blockHash($block->getPos()->getX(), $block->getPos()->getY(), $block->getPos()->getZ());
		if(!isset($this->placedBlocks[$blockHash])){
			$breakable = false;
			foreach($this->plugin->getConfig()->get("breakable-blocks") as $b){
				if($block->getId() === VanillaBlocks::fromString($b)->getId()){
					$breakable = true;
					break;
				}
			}
			if(!$breakable){
				$ev->setCancelled();
			}
		}else{
			unset($this->placedBlocks[$blockHash]);
		}
	}

	public function handleRegen(EntityRegainHealthEvent $ev){
		if($ev->getRegainReason() === EntityRegainHealthEvent::CAUSE_SATURATION){
			$ev->setCancelled();
		}
	}

	public function handleChunkLoad(ChunkLoadEvent $ev){
		$chunk = $ev->getChunk();
		foreach($chunk->getTiles() as $tile){
			if($tile instanceof Chest){
				$tileLocations = [];
				if(!in_array($tile->getPos(), $tileLocations)){
					$this->plugin->fillChest($tile);
					$tileLocations[] = $tile->getPos();
				}
			}
		}
	}
}
