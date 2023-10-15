<?php

declare(strict_types=1);

namespace Joestarfish\DoubleEnderChest;

use muqsit\invmenu\inventory\InvMenuInventory;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\block\tile\EnderChest;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;

class Main extends PluginBase implements Listener {
	private DataConnector $database;
	private array $players_second_ender_inventories = [];

	public function onEnable(): void {
		if (!$this->areVirionsLoaded()) {
			$this->getServer()
				->getPluginManager()
				->disablePlugin($this);
			return;
		}

		if (!InvMenuHandler::isRegistered()) {
			InvMenuHandler::register($this);
		}

		$this->database = libasynql::create(
			$this,
			$this->getConfig()->get('database'),
			[
				'sqlite' => 'sqlite.sql',
				'mysql' => 'mysql.sql',
			],
		);

		$this->database->executeGeneric('double_enderchest.init');
		$this->database->waitAll();

		$this->getServer()
			->getPluginManager()
			->registerEvents($this, $this);
	}

	public function onDisable(): void {
		$names = array_keys($this->players_second_ender_inventories);

		foreach ($names as $name) {
			$player = $this->getServer()->getPlayerExact($name);
			if ($player) {
				$this->saveSecondEnderInventory($player);
			}
			unset($this->players_second_ender_inventories[$name]);
		}

		if (isset($this->database)) {
			$this->database->waitAll();
			$this->database->close();
		}
	}

	public function onJoin(PlayerJoinEvent $event) {
		$this->initSecondEnderInventory($event->getPlayer());
	}

	public function onQuit(PlayerQuitEvent $event) {
		$this->saveSecondEnderInventory($event->getPlayer());
	}

	public function onInteract(PlayerInteractEvent $event) {
		if ($event->getAction() != PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
			return;
		}

		$player = $event->getPlayer();
		$name = $player->getName();

		// This allows the player to either place a block on one of the sides of the Ender Chest OR open the default Ender Chest if no item is held
		if ($player->isSneaking()) {
			return;
		}

		$position = $event->getBlock()->getPosition();
		$world = $position->getWorld();
		$tile = $world->getTile($position);

		if (!$tile instanceof EnderChest) {
			return;
		}

		$event->cancel();

		$second_inventory = $this->players_second_ender_inventories[$name];

		if ($second_inventory === null) {
			$player->sendMessage(
				'This inventory is not loaded yet. You may sneak + right click to open the default Ender Chest',
			);
			return;
		}

		$items = array_merge(
			$player->getEnderInventory()->getContents(true),
			$second_inventory,
		);

		[, $max] = $this->getMinMaxSlots($player);

		$inventory = new InvMenuInventory($max);
		$inventory->setContents($items);

		$menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST, $inventory);
		$menu->setName('Double Ender Chest');
		$menu->setInventoryCloseListener($this->inventoryCloseListener(...));

		$menu->send($player);
	}

	private function inventoryCloseListener(
		Player $player,
		Inventory $inventory,
	): void {
		$name = $player->getName();
		$ender_inventory = $player->getEnderInventory();
		[$min, $max] = $this->getMinMaxSlots($player);

		for ($slot = 0; $slot < $max; ++$slot) {
			$item = $inventory->getItem($slot);
			if ($slot < $min) {
				$ender_inventory->setItem($slot, $item);
				continue;
			}

			$this->players_second_ender_inventories[$name][$slot] = $item;
		}
	}

	private function getMinMaxSlots(Player $player) {
		$min = $player->getEnderInventory()->getSize();
		$max = $min * 2;
		return [$min, $max];
	}

	private function initSecondEnderInventory(Player $player) {
		$name = $player->getName();
		$uuid = $player->getUniqueId()->toString();

		// Already loaded or loading
		if (isset($this->players_second_ender_inventories[$name])) {
			return;
		}

		$this->players_second_ender_inventories[$name] = null;

		$this->database->executeSelect(
			'double_enderchest.load',
			['uuid' => $uuid],
			function (array $rows) use ($name) {
				$player = Server::getInstance()->getPlayerExact($name);

				if (!$player) {
					return;
				}

				if (count($rows) == 0) {
					$this->players_second_ender_inventories[$name] = [];
					return;
				}

				$items = [];

				[$slot] = $this->getMinMaxSlots($player);

				foreach (unserialize($rows[0]['inventory']) as $nbt) {
					$items[$slot] =
						$nbt === null
							? VanillaItems::AIR()
							: Item::nbtDeserialize($nbt);
					$slot++;
				}

				$this->players_second_ender_inventories[$name] = $items;
			},
		);
	}

	private function saveSecondEnderInventory(Player $player) {
		$name = $player->getName();
		$uuid = $player->getUniqueId()->toString();

		$inventory = $this->players_second_ender_inventories[$name];

		if ($inventory === null) {
			unset($this->players_second_ender_inventories[$name]);
			return;
		}

		$items = [];

		foreach ($inventory as $slot => $item) {
			$items[] = $item->isNull() ? null : $item->nbtSerialize($slot);
		}

		$data = serialize($items);

		$this->database->executeInsert(
			'double_enderchest.save',
			['uuid' => $uuid, 'inventory' => $data],
			function (int $insertId, int $affectedRows) use ($name) {
				$player = Server::getInstance()->getPlayerExact($name);
				if ($player) {
					return;
				}

				unset($this->players_second_ender_inventories[$name]);
			},
		);
	}

	private function areVirionsLoaded(): bool {
		if (!class_exists(libasynql::class) || !class_exists(InvMenu::class)) {
			$this->getLogger()->alert(
				'Please download this plugin as a phar file from https://poggit.pmmp.io/p/DoubleEnderChest',
			);
			return false;
		}

		return true;
	}
}
