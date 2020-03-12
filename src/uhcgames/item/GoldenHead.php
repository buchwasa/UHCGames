<?php
declare(strict_types=1);

namespace uhcgames\item;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\item\GoldenApple;
use pocketmine\utils\TextFormat as TF;

class GoldenHead extends GoldenApple{
	public function __construct(int $id, int $variant = 0, string $name = "Unknown"){
		parent::__construct($id, $variant, $name);
		if($this->meta === 1) $this->setCustomName(TF::RESET . TF::GOLD . "Golden Head" . TF::RESET);
	}

	public function getAdditionalEffects() : array{
		return [
			new EffectInstance(VanillaEffects::REGENERATION(), 20 * ($this->getMeta() == 1 ? 10 : 5), 1, false),
			new EffectInstance(VanillaEffects::ABSORPTION(), 20 * 120, 0, false)
		];
	}
}
