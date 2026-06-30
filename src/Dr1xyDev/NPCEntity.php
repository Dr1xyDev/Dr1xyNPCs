<?php

namespace Dr1xyDev;

use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\particle\FloatingTextParticle;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\RemoveEntityPacket;
use pocketmine\utils\UUID;

class NPCEntity {
    private $plugin;
    private $id;
    private $type;
    private $name;
    private $pos;
    private $levelName;
    private $skin;
    private $command = "";
    private $message = "";
    private $yaw = 0.0;
    private $pitch = 0.0;
    private $uuid;
    private $eid;
    private $floatingText = null;
    private $look = false;
    private $lastProcessed = "";

    public function __construct(Main $plugin, int $id, array $data){
        $this->plugin = $plugin;
        $this->id = $id;
        $this->type = $data["type"] ?? "human";
        $this->name = $data["name"] ?? "";
        $this->pos = $data["pos"] ?? [0,0,0];
        $this->levelName = $data["level"] ?? $plugin->getServer()->getDefaultLevel()->getFolderName();
        $this->skin = $data["skin"] ?? null;
        $this->command = $data["command"] ?? "";
        $this->message = $data["message"] ?? "";
        $this->yaw = isset($data["yaw"]) ? (float)$data["yaw"] : 0.0;
        $this->pitch = isset($data["pitch"]) ? (float)$data["pitch"] : 0.0;
        $this->look = $data["look"] ?? false;
        $this->uuid = UUID::fromRandom();
        $this->eid = Entity::$entityCount++;
    }

    public static function createFromData(Main $plugin, int $id, array $data){
        return new self($plugin, $id, $data);
    }

    public function getEid(): int {
        return $this->eid;
    }

    public function getFloatingTextEids(): array{
        $eids = [];
        if($this->floatingText === null) return $eids;
        if(!is_array($this->floatingText)) return $eids;
        foreach($this->floatingText as $ft){
            try{
                $eids[] = $ft->getId();
            }catch(\Throwable $e){}
        }
        return $eids;
    }

    private function removeOldFloatingTextPackets(): void {
        if($this->floatingText === null || !is_array($this->floatingText)) return;
        try {
            $players = $this->plugin->getServer()->getOnlinePlayers();
            foreach($this->floatingText as $oldParticle){
                try {
                    foreach($players as $pl){
                        try {
                            $oldParticle->despawnFrom($pl);
                        } catch(\Throwable $e){}
                    }
                } catch(\Throwable $e){}
            }
        } catch(\Throwable $e){}
        $this->floatingText = null;
        $this->lastProcessed = "";
    }

    private function createFloatingText(): void {
        $this->removeOldFloatingTextPackets();

        $yBase = $this->pos[1];
        $processed = $this->plugin->processRawForLabels($this->name);
        $lines = preg_split('/\{n\}|%n%/', $processed);
        foreach($lines as &$ln) $ln = trim($ln);
        unset($ln);

        $count = count($lines);
        if($count <= 0){
            $this->floatingText = null;
            $this->lastProcessed = "";
            return;
        }

        $baseY = $yBase + 2.3 + (($count - 1) * 0.25);

        $level = $this->plugin->getServer()->getLevelByName($this->levelName);
        if($level === null){
            $level = $this->plugin->getServer()->getDefaultLevel();
        }

        $particles = [];
        for($i = 0; $i < $count; $i++){
            $line = $lines[$i];
            $y = $baseY - ($i * 0.25);
            $posObj = new Position($this->pos[0], $y, $this->pos[2], $level);
            $particle = new FloatingTextParticle($posObj, "", $line);
            $particles[] = $particle;
        }

        $this->floatingText = $particles;
        $this->lastProcessed = $processed;
    }

    public function getLevelName(): string {
        return $this->levelName;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function setCommand(string $cmd): void {
        $this->command = $cmd;
        if(isset($this->plugin->stored["npcs"][$this->id])){
            $this->plugin->stored["npcs"][$this->id]["command"] = $cmd;
            $this->plugin->saveNPCs();
        }
    }

    public function setMessage(string $msg): void {
        $this->message = $msg;
        if(isset($this->plugin->stored["npcs"][$this->id])){
            $this->plugin->stored["npcs"][$this->id]["message"] = $msg;
            $this->plugin->saveNPCs();
        }
        $this->createFloatingText();
        $this->pushFloatingTextToLevelPlayers();
    }

    public function spawnToPlayer(Player $player): void {
        $playerName = $player->getName();

        if($this->plugin->isNpcVisibleToPlayer($playerName, $this->eid)){
            return;
        }

        if($this->type !== "floating"){
            try {
                $skinData = "";
                $skinId = "Standard_Custom";
                if($this->skin !== null && is_array($this->skin) && isset($this->skin['data'])){
                    $skinData = base64_decode($this->skin['data']);
                }
                if($this->skin !== null && is_array($this->skin) && isset($this->skin['name']) && strlen($this->skin['name']) > 0){
                    $skinId = $this->skin['name'];
                }
                if(strlen($skinData) < 64 * 32 * 4){
                    $skinData = str_repeat("\x00", 64 * 32 * 4);
                }

                $listPk = new PlayerListPacket();
                $listPk->type = PlayerListPacket::TYPE_ADD;
                $listPk->entries[] = [$this->uuid, $this->eid, $this->name, $skinId, $skinData];
                $player->dataPacket($listPk);

                $pk = new AddPlayerPacket();
                $pk->uuid = $this->uuid;
                $pk->username = $this->name;
                $pk->eid = $this->eid;
                $pk->x = (float)$this->pos[0];
                $pk->y = (float)$this->pos[1];
                $pk->z = (float)$this->pos[2];
                $pk->speedX = 0.0;
                $pk->speedY = 0.0;
                $pk->speedZ = 0.0;
                $pk->pitch = $this->pitch;
                $pk->yaw = $this->yaw;
                $pk->headYaw = $this->yaw;
                $pk->item = Item::get(0, 0, 0);

                $flags = (1 << Entity::DATA_FLAG_IMMOBILE);
                $pk->metadata = [
                    Entity::DATA_FLAGS => [Entity::DATA_TYPE_LONG, $flags],
                    Entity::DATA_NAMETAG => [Entity::DATA_TYPE_STRING, ""],
                ];

                $player->dataPacket($pk);

                $remPk = new PlayerListPacket();
                $remPk->type = PlayerListPacket::TYPE_REMOVE;
                $remPk->entries[] = [$this->uuid];
                $player->dataPacket($remPk);
            } catch(\Throwable $e){}
        }

        if($this->floatingText === null){
            $this->createFloatingText();
        }
        if($this->floatingText !== null && is_array($this->floatingText)){
            foreach($this->floatingText as $particle){
                try {
                    $particle->spawnTo($player);
                } catch(\Throwable $e){}
            }
        }

        $this->plugin->markNpcVisibleToPlayer($playerName, $this->eid);
    }

    public function updateFloatingTextIfNeeded(): void {
        try {
            $processed = $this->plugin->processRawForLabels($this->name);
            if($processed === $this->lastProcessed){
                return;
            }
            $this->createFloatingText();
            $this->pushFloatingTextToLevelPlayers();
        } catch(\Throwable $e){}
    }

    private function pushFloatingTextToLevelPlayers(): void {
        try {
            $level = $this->plugin->getServer()->getLevelByName($this->levelName);
            if($level === null) return;
            foreach($level->getPlayers() as $player){
                if(!($player instanceof Player)) continue;
                if($player->getLevel()->getFolderName() !== $this->levelName) continue;
                if($this->floatingText !== null && is_array($this->floatingText)){
                    foreach($this->floatingText as $particle){
                        try {
                            $particle->spawnTo($player);
                        } catch(\Throwable $e){}
                    }
                }
            }
        } catch(\Throwable $e){
        }
    }

    public function despawnAll(): void {
        try {
            foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
                $pk = new RemoveEntityPacket();
                $pk->eid = $this->eid;
                $player->dataPacket($pk);
                $pname = $player->getName();
                if(isset($this->plugin->playerSeen[$pname][$this->eid])) unset($this->plugin->playerSeen[$pname][$this->eid]);
            }
        } catch(\Throwable $e){}

        if($this->floatingText !== null && is_array($this->floatingText)){
            try {
                $players = $this->plugin->getServer()->getOnlinePlayers();
                foreach($this->floatingText as $particle){
                    try {
                        foreach($players as $pl){
                            try {
                                $particle->despawnFrom($pl);
                            } catch(\Throwable $e){}
                        }
                    } catch(\Throwable $e){}
                }
            } catch(\Throwable $e){}
            $this->floatingText = null;
        }

        $this->plugin->unregisterEid($this->eid);
    }

    public function getSaveData(): array {
        return [
            "type" => $this->type,
            "name" => $this->name,
            "command" => $this->command,
            "message" => $this->message,
            "look" => $this->look,
            "level" => $this->levelName,
            "pos" => $this->pos,
            "yaw" => $this->yaw,
            "pitch" => $this->pitch,
            "skin" => $this->skin
        ];
    }

    public function updateLookAtPlayers(Main $main): void {
        if(!$this->look) return;

        $level = $main->getServer()->getLevelByName($this->levelName);
        if($level === null) return;

        $closest = null;
        $minDist = 4.01;
        foreach($level->getPlayers() as $pl){
            $dx = $pl->x - $this->pos[0];
            $dy = $pl->y - $this->pos[1];
            $dz = $pl->z - $this->pos[2];
            $d = sqrt($dx*$dx + $dy*$dy + $dz*$dz);
            if($d <= 4 && $d < $minDist){
                $closest = $pl;
                $minDist = $d;
            }
        }

        if($closest !== null){
            $dx = $closest->x - $this->pos[0];
            $dz = $closest->z - $this->pos[2];
            $dy = ($closest->y + $closest->getEyeHeight()) - ($this->pos[1] + 1.62);
            $xz = sqrt($dx * $dx + $dz * $dz);
            if($xz != 0){
                $this->yaw = atan2(-$dx, $dz) * 180 / M_PI;
                $this->pitch = -atan2($dy, $xz) * 180 / M_PI;
            }
        }
    }
}
