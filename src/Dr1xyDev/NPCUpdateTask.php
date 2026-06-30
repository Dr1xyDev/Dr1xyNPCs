<?php

namespace Dr1xyDev;

use pocketmine\scheduler\PluginTask;

/**
 * Tarea para actualizar animaciones y rotación de los NPCs
 */
class NPCUpdateTask extends PluginTask {
    private $plugin;
    public function __construct(Main $plugin){
        parent::__construct($plugin);
        $this->plugin = $plugin;
    }
    public function onRun(int $currentTick): void{
        try{
            $this->plugin->updateAllNPCs();
            $this->plugin->updateLookAtPlayers();
        }catch(\Throwable $e){
        }
    }
}
