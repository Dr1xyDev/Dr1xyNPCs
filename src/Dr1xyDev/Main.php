<?php

namespace Dr1xyDev;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\RemoveEntityPacket;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\utils\UUID;

class Main extends PluginBase implements Listener {
    /** @var NPCEntity[] */
    private $npcs = [];
    private $nextId = 1;
    private $pendingAddCmd = [];
    private $pendingDelCmd = [];
    private $pendingSetView = [];
    private $pendingSetMsg = [];
    private $pendingDelete = [];
    private $lastViewerName = "";
    private $dataFile;
    private $stored = ["nextId" => 1, "npcs" => []];
    private $entityDeleteMode = [];
    /** mapping eid -> npcId (packet-only) */
    private $eidToNpcId = [];
    /** tracking which player has been sent which EID to avoid re-sending AddPlayer/particles */
    private $playerSeen = []; // [playerName => [eid => true]]

    public function onEnable() {
        $dir = $this->getDataFolder();
        if(!is_dir($dir)){
            @mkdir($dir, 0777, true);
        }
        if(!is_dir($dir)){
            throw new \RuntimeException("Dr1xyNPC: no se pudo crear la carpeta de datos: " . $dir);
        }
        $this->dataFile = $dir . "npc.dat";
        if(!is_file($this->dataFile)){
            file_put_contents($this->dataFile, serialize($this->stored));
        }
        $this->loadNPCs();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        // intercept interact packets
        $this->getServer()->getScheduler()->scheduleRepeatingTask(new NPCUpdateTask($this), 40);
    }

    public function onDisable(): void {
        try{
            $this->saveNPCs();
            foreach($this->npcs as $npc){
                if($npc !== null){
                    $npc->despawnAll();
                }
            }
            // clear mappings
            $this->eidToNpcId = [];
            $this->playerSeen = [];
        }catch(\Throwable $e){
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $ev){
        try{
            $p = $ev->getPlayer();
            $this->lastViewerName = $p->getName();

            foreach($this->npcs as $id => $npc){
                if($npc === null) continue;
                if($npc->getLevelName() === $p->getLevel()->getFolderName()){
                    $npc->spawnToPlayer($p);
                }
            }
        }catch(\Throwable $e){
        }
    }

    /**
     * Arreglo del Bug: Detectar cuando el jugador cambia de mundo o dimensión.
     * Limpiamos el rastro de NPCs vistos y ejecutamos un spawn con retraso.
     */
    public function onLevelChange(EntityLevelChangeEvent $ev){
        $p = $ev->getEntity();
        if($p instanceof Player){
            $pname = $p->getName();

            // Paso 1: Limpiar registro de visibilidad por completo para este jugador
            // Al cambiar de dimensión el cliente borra todo, así que debemos resetear nuestro registro.
            if(isset($this->playerSeen[$pname])){
                unset($this->playerSeen[$pname]);
            }

            // Paso 2: Programar el re-spawn con un retraso de 1 segundo (20 ticks)
            // Esto evita que los paquetes se envíen mientras el jugador está en la pantalla de carga.
            $this->getServer()->getScheduler()->scheduleDelayedTask(new DelayedSpawnTask($this, $p), 25);
        }
    }

    /**
     * Función auxiliar usada por el DelayedSpawnTask
     */
    public function spawnNpcsForPlayer(Player $p){
        if(!$p->isOnline()) return;
        $levelName = $p->getLevel()->getFolderName();
        foreach($this->npcs as $id => $npc){
            if($npc === null) continue;
            if($npc->getLevelName() === $levelName){
                $npc->spawnToPlayer($p);
            }
        }
    }

    public function onPlayerQuit(PlayerQuitEvent $ev){
        try{
            $players = $this->getServer()->getOnlinePlayers();
            $name = "";
            foreach($players as $pl){
                if($pl instanceof Player){
                    $name = $pl->getName();
                    break;
                }
            }
            $this->lastViewerName = $name;
            // cleanup seen map for leaving player to avoid memory leak
            $left = $ev->getPlayer()->getName();
            if(isset($this->playerSeen[$left])) unset($this->playerSeen[$left]);
        }catch(\Throwable $e){
        }
    }

    public function loadNPCs(): void {
        $this->npcs = [];
        $this->stored = ["nextId" => 1, "npcs" => []];
        $this->eidToNpcId = [];
        if(!is_file($this->dataFile)) return;
        $raw = file_get_contents($this->dataFile);
        if($raw === false || $raw === "") return;
        $data = @unserialize($raw);
        if(!is_array($data)) return;
        if(isset($data["nextId"])) $this->nextId = (int)$data["nextId"];
        if(isset($data["npcs"]) && is_array($data["npcs"])){
            $this->stored["npcs"] = $data["npcs"];
            foreach($data["npcs"] as $id => $entry){
                if(!is_array($entry)) continue;
                $npc = NPCEntity::createFromData($this, (int)$id, $entry);
                if($npc !== null){
                    $this->npcs[$id] = $npc;
                    // register mapping
                    $this->registerEid($npc->getEid(), $id);
                }
            }
        }
        $this->stored["nextId"] = $this->nextId;
    }

    public function saveNPCs(): void {
        $out = [];
        $out["nextId"] = $this->nextId;
        $out["npcs"] = [];
        foreach($this->npcs as $id => $npc){
            if($npc === null) continue;
            $out["npcs"][(int)$id] = $npc->getSaveData();
        }
        $this->stored = $out;
        $tmp = $this->dataFile . ".tmp";
        file_put_contents($tmp, serialize($out));
        @rename($tmp, $this->dataFile);
    }

    public function createNPC(Player $player, string $type, string $name): int {
        $id = $this->nextId++;
        $entry = [
            "type" => $type,
            "name" => $name,
            "command" => "",
            "message" => "",
            "look" => false,
            "level" => $player->getLevel()->getFolderName(),
            "pos" => [$player->getX(), $player->getY(), $player->getZ()],
            "yaw" => (float)$player->getYaw(),
            "pitch" => (float)$player->getPitch(),
            "scale" => 1.0
        ];
        if($type === "human" || $type === "floating"){
            $entry["skin"] = ["data" => base64_encode($player->getSkinData()), "name" => $player->getSkinId()];
        }
        $npc = NPCEntity::createFromData($this, $id, $entry);
        if($npc !== null){
            $this->npcs[$id] = $npc;
            $this->stored["npcs"][$id] = $entry;
            $this->stored["nextId"] = $this->nextId;
            $this->saveNPCs();
            // register mapping
            $this->registerEid($npc->getEid(), $id);

            // spawn immediately to players in same level
            foreach($this->getServer()->getOnlinePlayers() as $pl){
                if($pl->getLevel()->getFolderName() === $entry["level"]){
                    $npc->spawnToPlayer($pl);
                }
            }
        }
        return $id;
    }

    public function removeNPC(int $id): bool {
        if(isset($this->npcs[$id])){
            $npc = $this->npcs[$id];
            $npc->despawnAll();
            // unregister mapping
            $this->unregisterEid($npc->getEid());
            unset($this->npcs[$id]);
            if(isset($this->stored["npcs"][$id])) unset($this->stored["npcs"][$id]);
            $this->stored["nextId"] = $this->nextId;
            $this->saveNPCs();
            return true;
        } else {
            if(isset($this->stored["npcs"][$id])){
                unset($this->stored["npcs"][$id]);
                $this->stored["nextId"] = $this->nextId;
                $this->saveNPCs();
                return true;
            }
        }
        return false;
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        if ($command->getName() !== "npc") return false;
        if (!$sender instanceof Player) {
            $sender->sendMessage("§cComando solo para jugadores.");
            return true;
        }
        if(!isset($args[0])){
             $sender->sendMessage("§f: : §eNPC Commands §f: :§r");
            $sender->sendMessage("§b- §f/npc add §7< §fhuman§7|§ffloating§7 > < §fname §7>");
            $sender->sendMessage("§b- §f/npc addcmd §7< §fcomando §7>§r");
            $sender->sendMessage("§b- §f/npc delcmd");
            $sender->sendMessage("§b- §f/npc setmsg §7< §fmensaje §7>");
            $sender->sendMessage("§b- §f/npc list");
            $sender->sendMessage("§b- §f/npc delete §7< §fid §7>");
            $sender->sendMessage("§b");
            $sender->sendMessage("§7Plugin by §f: : §bDr1xy§fDev §f: :");
            return true;
        }
        $sub = strtolower($args[0]);
        switch($sub){
            case "add":
                if(!isset($args[2])){
                    $sender->sendMessage("§f: : §f/npc add §7< §fhuman§7|§ffloating§7 > < §fname §7>");
                    return true;
                }
                $type = strtolower($args[1]);
                if($type !== "human" && $type !== "floating"){
                    $sender->sendMessage("§f: : §cuse human o floating");
                    return true;
                }
                $name = $this->joinNameArgs(array_slice($args, 2));
                $id = $this->createNPC($sender, $type, $name);
                $sender->sendMessage("§f: : §7NPC creado con ID: §e{$id}");
                return true;
            case "addcmd":
                if(!isset($args[1])){
                    $sender->sendMessage("§f: : §f/npc addcmd §7< §fcomando §7>§r");
                    return true;
                }
                $cmd = implode(" ", array_slice($args, 1));
                $this->pendingAddCmd[strtolower($sender->getName())] = $cmd;
                $sender->sendMessage("§f: : §7Toca al NPC para agregar el comando");
                return true;
            case "delcmd":
                $this->pendingDelCmd[strtolower($sender->getName())] = true;
                $sender->sendMessage("§f: : §7Toca el NPC para eliminar el comando");
                return true;
            case "setmsg":
                if(!isset($args[1])){
                    $sender->sendMessage("§f: : §f/npc setmsg §7< §fmensaje §7>");
                    return true;
                }
                $msg = implode(" ", array_slice($args, 1));
                $this->pendingSetMsg[strtolower($sender->getName())] = $msg;
                $sender->sendMessage("§f: : §7Toca el NPC para agregar el mensaje");
                return true;
            case "list":
                $sender->sendMessage("§f: : §7Lista de NPCs §f: :");
                foreach($this->stored["npcs"] as $key => $val){
                    if(!is_array($val)) continue;
                    $sender->sendMessage("§b- §7ID: §e{$key} §7| Tipo: §e{$val['type']} §7| Nombre:§e {$val['name']}");
                }
                return true;
            case "delete":
                if(isset($args[1]) && is_numeric($args[1])){
                    $id = (int)$args[1];
                    if($this->removeNPC($id)){
                        $sender->sendMessage("§f: : §7NPC §e{$id}§7 borrado");
                    } else {
                        $sender->sendMessage("§f: : §7NPC no encontrado");
                    }
                } else {
                    $this->pendingDelete[strtolower($sender->getName())] = true;
                    $sender->sendMessage("§f: : §7Toca un NPC para borrarlo");
                }
                return true;
            default:
                $sender->sendMessage("§f: : §7Subcomando invalido");
                return true;
        }
    }

    private function joinNameArgs(array $parts): string {
        $s = implode(" ", $parts);
        $s = str_replace("_", " ", $s);
        return $s;
    }

    /**
     * Packet handler to detect touches (InteractPacket).
     */
    public function onDataPacketReceive(DataPacketReceiveEvent $event): void {
        $pk = $event->getPacket();
        $player = $event->getPlayer();

        if(!($pk instanceof InteractPacket)) return;

        // Only care about left or right click
        if(!in_array($pk->action, [InteractPacket::ACTION_LEFT_CLICK, InteractPacket::ACTION_RIGHT_CLICK], true)){
            return;
        }

        $eid = $pk->target ?? $pk->eid ?? null;
        if($eid === null) return;

        $npcId = $this->getNPCIdFromEid((int)$eid);
        if($npcId === null) return;

        // found npc
        $pname = strtolower($player->getName());

        // play click sound (optional UX)
        try {
            $sound = new PlaySoundPacket();
            $sound->soundName = "random.click";
            $sound->x = $player->x;
            $sound->y = $player->y;
            $sound->z = $player->z;
            $sound->volume = 1;
            $sound->pitch = 1;
            $player->dataPacket($sound);
        } catch(\Throwable $e){}

        // handle pending operations (same semantics as old onDamage)
        if(isset($this->pendingAddCmd[$pname])){
            $cmd = $this->pendingAddCmd[$pname];
            unset($this->pendingAddCmd[$pname]);
            if(isset($this->npcs[$npcId])){
                $this->npcs[$npcId]->setCommand($cmd);
                $player->sendMessage("§f: : §7Comando asignado §e{$cmd}");
            }
            return;
        }
        if(isset($this->pendingDelCmd[$pname])){
            unset($this->pendingDelCmd[$pname]);
            if(isset($this->npcs[$npcId])){
                $this->npcs[$npcId]->setCommand("");
                $player->sendMessage("§f: : §7Comando eliminado");
            }
            return;
        }
        if(isset($this->pendingSetMsg[$pname])){
            $msg = $this->pendingSetMsg[$pname];
            unset($this->pendingSetMsg[$pname]);
            if(isset($this->npcs[$npcId])){
                $this->npcs[$npcId]->setMessage($msg);
                $player->sendMessage("§f: : §7Mensaje asignado");
            }
            return;
        }
        if(isset($this->pendingDelete[$pname])){
            unset($this->pendingDelete[$pname]);
            $this->removeNPC($npcId);
            $player->sendMessage("§f: : §7NPC borrado");
            return;
        }

        // regular interaction
        if(isset($this->npcs[$npcId])){
            $npc = $this->npcs[$npcId];
            // if sneaking emulate the old check: require not sneaking to trigger
            if(!$player->isSneaking()){
                $cmd = $npc->getCommand();
                $msg = $npc->getMessage();
                if($cmd !== ""){
                    $this->getServer()->dispatchCommand($player, $cmd);
                } elseif($msg !== ""){
                    $parsed = $this->parsePlaceholders($msg, $player);
                    $player->sendMessage($parsed);
                }
            }
        }
    }

    /**
     * Register/unregister EID mapping for packet-only NPCs
     */
    public function registerEid(int $eid, int $npcId): void {
        $this->eidToNpcId[$eid] = $npcId;
    }
    public function unregisterEid(int $eid): void {
        if(isset($this->eidToNpcId[$eid])) unset($this->eidToNpcId[$eid]);
        // also clean playerSeen entries that referenced this eid
        foreach($this->playerSeen as $pname => $map){
            if(isset($this->playerSeen[$pname][$eid])) unset($this->playerSeen[$pname][$eid]);
        }
    }
    public function getNPCIdFromEid(int $eid): ?int {
        return $this->eidToNpcId[$eid] ?? null;
    }

    /**
     * playerSeen helpers
     */
    public function isNpcVisibleToPlayer(string $playerName, int $eid): bool {
        return isset($this->playerSeen[$playerName][$eid]);
    }
    public function markNpcVisibleToPlayer(string $playerName, int $eid): void {
        if(!isset($this->playerSeen[$playerName])) $this->playerSeen[$playerName] = [];
        $this->playerSeen[$playerName][$eid] = true;
    }
    public function unmarkNpcVisibleForAll(int $eid): void {
        foreach($this->playerSeen as $pn => $map){
            if(isset($this->playerSeen[$pn][$eid])) unset($this->playerSeen[$pn][$eid]);
        }
    }

    public function updateAllNPCs(): void {
        // Only update floating text if changed — NPCEntity already checks lastProcessed
        foreach($this->npcs as $id => $npc){
            if($npc === null) continue;
            $npc->updateFloatingTextIfNeeded();
        }
    }

    public function updateLookAtPlayers(): void {
        foreach($this->npcs as $id => $npc){
            if($npc === null) continue;
            $npc->updateLookAtPlayers($this);
        }
    }

    private function parsePlaceholders(string $msg, Player $viewer): string {
        $out = str_replace(["%n%","{n}"], "\n", $msg);
        $out = str_replace(["%o%","{o}"], (string)count($this->getServer()->getOnlinePlayers()), $out);
        $out = str_replace(["%player%","{player}"], $viewer->getName(), $out);
        if(preg_match_all('/%([^%]+)%/', $out, $matches)){
            foreach($matches[1] as $key){
                $lower = strtolower($key);
                if(in_array($lower, ["n","o","player"])) continue;
                $count = $this->getWorldOnlineCount($key);
                $out = str_replace('%'.$key.'%', (string)$count, $out);
            }
        }
        return $out;
    }

    public function processRawForLabels(string $raw): string {
        $out = str_replace(["%o%","{o}"], (string)count($this->getServer()->getOnlinePlayers()), $raw);
        $viewerName = $this->lastViewerName !== "" ? $this->lastViewerName : "";
        $out = str_replace(["%player%","{player}"], $viewerName, $out);
        if(preg_match_all('/%([^%]+)%/', $out, $matches)){
            foreach($matches[1] as $key){
                $lower = strtolower($key);
                if(in_array($lower, ["n","o","player"])) continue;
                $count = $this->getWorldOnlineCount($key);
                $out = str_replace('%'.$key.'%', (string)$count, $out);
            }
        }
        return $out;
    }

    private function getWorldOnlineCount(string $folderName): int {
        $folderNameLower = strtolower($folderName);
        foreach($this->getServer()->getLevels() as $level){
            try {
                if(strtolower($level->getFolderName()) === $folderNameLower){
                    return count($level->getPlayers());
                }
            } catch(\Throwable $e){
            }
        }
        return 0;
    }
}
