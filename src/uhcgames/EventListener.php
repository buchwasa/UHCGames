<?php
declare(strict_types=1);

namespace uhcgames;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\item\GoldenApple;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\block\tile\Chest;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;
use uhcgames\game\type\GamePhase;
use function in_array;

class EventListener implements Listener{
	/** @var Loader */
	private $plugin;
	/** @var array */
	private $placedBlocks = [];

	public function __construct(Loader $plugin){
		$plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
		$this->plugin = $plugin;
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
		if($this->plugin->getGameTask()->getGamePhase() <= GamePhase::PHASE_COUNTDOWN){
			$this->plugin->addToGame($player);
		}else{
			$player->disconnect("This game has already started!");
		}
	}

	public function handleJoin(PlayerJoinEvent $ev){
		$player = $ev->getPlayer();
		$ev->setJoinMessage("");

		$this->plugin->randomizeSpawn($player, $player->getWorld());

		$player->setImmobile();
	}

	public function handleConsume(PlayerItemConsumeEvent $ev){
		$player = $ev->getPlayer();
		$item = $ev->getItem();
		if($item instanceof GoldenApple && $item->getNamedTag()->hasTag("goldenhead")){
			$player->getEffects()->remove(VanillaEffects::REGENERATION());
			$player->getEffects()->add(new EffectInstance(VanillaEffects::REGENERATION(), 200, 1));
		}
	}

	public function handleDamage(EntityDamageEvent $ev){
		if($this->plugin->getGameTask()->getGamePhase() <= GamePhase::PHASE_COUNTDOWN){
			$ev->setCancelled();
		}
	}

	public function handleLeave(PlayerQuitEvent $ev){
		$player = $ev->getPlayer();
		$ev->setQuitMessage("");
		if($this->plugin->isInGame($player)){
			$this->plugin->removeFromGame($player);
		}elseif($this->plugin->isSpawnUsedByPlayer($player)){
			$this->plugin->removePlayerUsedSpawn($player);
		}
	}

	public function handleDeath(PlayerDeathEvent $ev){
		$player = $ev->getPlayer();
		$goldenHead = VanillaItems::GOLDEN_APPLE();
		$goldenHead->getNamedTag()->setInt("goldenhead", 1);
		$goldenHead->setCustomName(TextFormat::RESET . TextFormat::GOLD . "Golden Head");
		$player->getWorld()->dropItem($player->getPosition(), $goldenHead);
		$player->setGamemode(GameMode::SPECTATOR());
		if($this->plugin->isInGame($player)){
			$this->plugin->removeFromGame($player);
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
		$canBePlaced = false;
		foreach($this->plugin->getConfig()->get("placeable-blocks") as $b){
			if($block->getId() === VanillaBlocks::fromString($b)->getId()){
				$canBePlaced = true;
				break;
			}
		}
		if(!$canBePlaced){
			$ev->setCancelled();
		}else{
			$this->placedBlocks[World::blockHash($block->getPos()->getX(), $block->getPos()->getY(), $block->getPos()->getZ())] = $player->getName();
		}
	}

	public function handleBreak(BlockBreakEvent $ev){
		$block = $ev->getBlock();

		$blockHash = World::blockHash($block->getPos()->getX(), $block->getPos()->getY(), $block->getPos()->getZ());
		if(!isset($this->placedBlocks[$blockHash])){
			$canBeBroken = false;
			foreach($this->plugin->getConfig()->get("breakable-blocks") as $b){
				if($block->getId() === VanillaBlocks::fromString($b)->getId()){
					$canBeBroken = true;
					break;
				}
			}
			if(!$canBeBroken){
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
