<?php

namespace Dr1xyDev;

use pocketmine\scheduler\PluginTask;
use pocketmine\Player;

/**
 * Nueva Tarea para solucionar el bug de desaparición en cambios de dimensión.
 * Envía los NPCs con un pequeño retraso para asegurar que el cliente cargó el mapa.
 */
class DelayedSpawnTask extends PluginTask {
    private $player;
    public function __construct(Main $plugin, Player $player){
        parent::__construct($plugin);
        $this->player = $player;
    }
    public function onRun(int $currentTick): void{
        if($this->player instanceof Player && $this->player->isOnline()){
            $this->getOwner()->spawnNpcsForPlayer($this->player);
        }
    }
}
