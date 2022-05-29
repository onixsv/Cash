<?php

/*
 *       _       _        ___ _____ _  ___
 *   __ _| |_   _(_)_ __  / _ \___ // |/ _ \
 * / _` | \ \ / / | '_ \| | | ||_ \| | (_) |
 * | (_| | |\ V /| | | | | |_| |__) | |\__, |
 *  \__,_|_| \_/ |_|_| |_|\___/____/|_|  /_/
 *
 * Copyright (C) 2019 alvin0319
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Cash;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat;

class Cash extends PluginBase implements Listener{
	use SingletonTrait;

	public static string $prefix = "§d<§f시스템§d> §f";

	public const FORM_ID_MAIN = 47461;

	public const FORM_ID_CONFIRM = 83615;

	protected array $queue = [];

	/** @var Config */
	protected Config $config;

	protected array $db = [];

	protected function onLoad() : void{
		self::setInstance($this);
	}

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->config = new Config($this->getDataFolder() . "Config.yml", Config::YAML, [
			"player" => [],
			"shop" => []
		]);
		$this->db = $this->config->getAll();

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
			$this->save();
		}), 1200 * 10);
	}

	protected function onDisable() : void{
		$this->save();
	}

	public function save(){
		$this->config->setAll($this->db);
		$this->config->save();
	}

	public function setCash($player, int $cash){
		$player = $player instanceof Player ? strtolower($player->getName()) : strtolower($player);
		$this->db["player"] [$player] = $cash;
	}

	public function addCash($player, int $cash){
		$player = $player instanceof Player ? strtolower($player->getName()) : strtolower($player);
		$this->db["player"] [$player] += $cash;
	}

	public function getCash($player) : ?int{
		$player = $player instanceof Player ? strtolower($player->getName()) : strtolower($player);
		return $this->db["player"] [$player] ?? null;
	}

	public function reduceCash($player, int $cash){
		$player = $player instanceof Player ? strtolower($player->getName()) : strtolower($player);
		$this->db["player"] [$player] -= $cash;
	}

	public function isExistsData($player) : bool{
		$player = $player instanceof Player ? strtolower($player->getName()) : strtolower($player);
		return isset($this->db["player"] [$player]);
	}

	public function handlePlayerJoin(PlayerJoinEvent $event){
		$player = $event->getPlayer();

		if(!$this->isExistsData($player)){
			$this->setCash($player, 0);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		switch($args[0] ?? "x"){
			case "상점":
				if($sender instanceof Player){
					$this->sendShopUI($sender);
				}
				break;
			case "정보":
			case "info": // For Console
				array_shift($args);
				$name = array_shift($args);
				if(!isset($name)){
					$name = $sender->getName();
				}

				if($this->getCash($name) !== null){
					$sender->sendMessage(Cash::$prefix . $name . " 님의 캐시: " . $this->koreanWonFormat($this->getCash($name)));
				}else{
					$sender->sendMessage(Cash::$prefix . $name . " 님은 서버에 접속한 적이 없습니다.");
				}
				break;
			case "지급":
			case "add": // For Console
				if(!$sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					break;
				}
				array_shift($args);
				$name = array_shift($args);
				$cash = array_shift($args);
				if(!isset($name)){
					$sender->sendMessage(Cash::$prefix . "/캐시 추가 [ 닉네임 ] [ 양 ]");
					break;
				}

				if($this->getCash($name) === null){
					$sender->sendMessage(Cash::$prefix . "해당 플레이어는 서버에 접속한 적이 없습니다.");
					break;
				}

				if(!isset($cash) or !is_numeric($cash)){
					$sender->sendMessage(Cash::$prefix . "지급할 캐시의 양은 숫자여야 합니다.");
					break;
				}

				$this->addCash($name, (int) $cash);
				$sender->sendMessage(Cash::$prefix . "지급하였습니다.");
				break;
			case "뺏기":
			case "reduce": //For Console
				if(!$sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					break;
				}
				array_shift($args);
				$name = array_shift($args);
				$cash = array_shift($args);
				if(!isset($name)){
					$sender->sendMessage(Cash::$prefix . "/캐시 뱃기 [ 닉네임 ] [ 양 ]");
					break;
				}

				if($this->getCash($name) === null){
					$sender->sendMessage(Cash::$prefix . "해당 플레이어는 서버에 접속한 적이 없습니다.");
					break;
				}

				if(!isset($cash) or !is_numeric($cash)){
					$sender->sendMessage(Cash::$prefix . "뺏을 캐시의 양은 숫자여야 합니다.");
					break;
				}

				$this->reduceCash($name, (int) $cash);
				$sender->sendMessage(Cash::$prefix . "뺏었습니다.");
				break;
			case "설정":
			case "set":
				if(!$sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					break;
				}
				array_shift($args);
				$name = array_shift($args);
				$cash = array_shift($args);

				if(!isset($name)){
					$sender->sendMessage(Cash::$prefix . "/캐시 설정 [ 닉네임 ] [ 양 ]");
					break;
				}

				if($this->getCash($name) === null){
					$sender->sendMessage(Cash::$prefix . "해당 플레이어는 서버에 접속한 적이 없습니다.");
					break;
				}

				if(!isset($cash) or !is_numeric($cash)){
					$sender->sendMessage(Cash::$prefix . "설정할 캐시의 양은 숫자여야 합니다.");
					break;
				}

				$this->setCash($name, (int) $cash);
				$sender->sendMessage(Cash::$prefix . "설정하였습니다.");
				break;
			case "캐시샵생성":
				if(!$sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					break;
				}
				array_shift($args);
				$name = array_shift($args);
				$cash = array_shift($args);
				if(!$sender instanceof Player)
					break;
				$item = $sender->getInventory()->getItemInHand();

				if(!isset($name)){
					$sender->sendMessage(Cash::$prefix . "/캐시 캐시샵생성 [ 이름 ] [ 필요 캐시 ] - 손에 든 아이템으로 캐시 상점을 생성합니다.");
					break;
				}

				if($this->getCashShop($name) !== null){
					$sender->sendMessage(Cash::$prefix . "해당 이름의 캐시상점이 등록되어 있습니다.");
					break;
				}

				if(!isset($cash) or !is_numeric($cash)){
					$sender->sendMessage(Cash::$prefix . "캐시의 양은 정수여야 합니다.");
					break;
				}

				$this->addCashShop((string) $name, $item, (int) $cash);
				$sender->sendMessage(Cash::$prefix . "추가되었습니다.");
				break;

			case "캐시샵제거":
				if(!$sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					break;
				}
				array_shift($args);
				$name = array_shift($args);
				if(!$sender instanceof Player)
					break;
				if(!isset($name)){
					$sender->sendMessage(Cash::$prefix . "/캐시 캐시샵제거 [ 이름 ] [ 필요 캐시 ] - 손에 든 아이템으로 캐시 상점을 생성합니다.");
					break;
				}

				if($this->getCashShop($name) === null){
					$sender->sendMessage(Cash::$prefix . "해당 이름의 캐시상점이 등록되어있지 않습니다.");
					break;
				}
				unset($this->db["shop"] [$name]);
				$sender->sendMessage(Cash::$prefix . "제거되었습니다.");
				break;
			default:
				$sender->sendMessage(Cash::$prefix . "/캐시 정보 [ 닉네임 ] - 캐시 정보를 봅니다. (닉네임 칸은 비워둘 시 자신)");
				$sender->sendMessage(Cash::$prefix . "/캐시 상점 - 캐시상점을 엽니다.");
				if($sender->hasPermission(DefaultPermissions::ROOT_OPERATOR)){
					foreach([
						["/캐시 설정", "캐시를 설정합니다."],
						["/캐시 지급 [ 닉네임 ] [ 양 ]", "캐시를 지급합니다."],
						["/캐시 뺏기 [ 닉네임 ] [ 양 ]", "캐시를 뺏습니다."],
						["/캐시 캐시샵생성 [ 이름 ] [ 필요 캐시 ]", "손에 든 아이템으로 캐시상점을 생성합니다."],
						["/캐시 캐시샵제거 [ 이름 ]", "캐시상점을 제거합니다."]
					] as $usage){
						$sender->sendMessage(Cash::$prefix . $usage[0] . " - " . $usage[1]);
					}
				}
		}
		return true;
	}

	public function koreanWonFormat(int $money) : string{
		$elements = [];
		if($money >= 1000000000000){
			$elements[] = floor($money / 1000000000000) . "조";
			$money %= 1000000000000;
		}
		if($money >= 100000000){
			$elements[] = floor($money / 100000000) . "억";
			$money %= 100000000;
		}
		if($money >= 10000){
			$elements[] = floor($money / 10000) . "만";
			$money %= 10000;
		}
		if(count($elements) == 0 || $money > 0){
			$elements[] = $money;
		}
		return implode(" ", $elements) . "캐시";
	}

	public function addCashShop(string $name, Item $item, int $cash){
		$this->db["shop"] [$name] = ["item" => $item->jsonSerialize(), "cash" => $cash];
	}

	public function getCashShop(string $name) : ?array{
		return $this->db["shop"] [$name] ?? null;
	}

	public function sendShopUI(Player $player){
		$arr = [];
		foreach($this->db["shop"] as $name => $value){
			$arr[] = ["text" => $name . " §r상점" . TextFormat::EOL . $this->koreanWonFormat((int) $value["cash"])];
		}

		$encode = [
			"type" => "form",
			"title" => "§l§8CashShop - Onix",
			"content" => "§b§l* 구매하고 싶은 상점을 선택해주세요.",
			"buttons" => $arr
		];

		$pk = new ModalFormRequestPacket();
		$pk->formId = self::FORM_ID_MAIN;
		$pk->formData = json_encode($encode);

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	public function sendConfirmUI(Player $player){
		$name = $this->queue[$player->getName()];
		$encode = [
			"type" => "modal",
			"title" => "§l§8* 캐시상점 구매 창",
			"content" => "정말 " . $name . " 을(를) 구매하시겠습니까?" . TextFormat::EOL . "필요한 캐시 : " . $this->koreanWonFormat($this->getCashShop($name)["cash"]) . " 내 캐시 : " . $this->koreanWonFormat($this->getCash($player)),
			"button1" => "네",
			"button2" => "아니요"
		];

		$pk = new ModalFormRequestPacket();
		$pk->formId = self::FORM_ID_CONFIRM;
		$pk->formData = json_encode($encode);

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 *
	 * @handleCancelled true
	 */
	public function handleReceivePacket(DataPacketReceiveEvent $event){
		$packet = $event->getPacket();
		$player = $event->getOrigin();
		if(!$player instanceof NetworkSession){
			return;
		}
		$player = $player->getPlayer();
		if(!$player instanceof Player){
			return;
		}

		if($packet instanceof ModalFormResponsePacket){
			$id = $packet->formId;
			$data = json_decode($packet->formData, true);

			if($id === self::FORM_ID_MAIN){
				if($data !== null){
					$arr = [];

					foreach($this->db["shop"] as $name => $value){
						$arr[] = $name;
					}

					$this->queue[$player->getName()] = $arr[$data];
					$this->sendConfirmUI($player);
				}
			}
			if($id === self::FORM_ID_CONFIRM){
				if($data !== null){
					try{
						$name = $this->queue[$player->getName()];

						if($data){
							$shop = $this->getCashShop($name);
							$needCash = (int) $shop["cash"];

							if($this->getCash($player) >= $needCash){
								$item = Item::jsonDeserialize($shop["item"]);
								$player->getInventory()->addItem($item);

								$this->reduceCash($player, $needCash);

								$player->sendMessage(Cash::$prefix . "구매하였습니다.");
							}else{
								$player->sendMessage(Cash::$prefix . "캐시가 부족합니다.");
							}
						}
					}finally{
						unset($this->queue[$player->getName()]);
					}
				}
			}
		}
	}
}