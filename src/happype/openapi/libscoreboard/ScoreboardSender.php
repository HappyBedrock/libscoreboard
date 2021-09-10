<?php

declare(strict_types=1);

namespace happype\openapi\libscoreboard;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function in_array;

final class ScoreboardSender implements Listener {

	/** @var array<string, string> */
	private static array $players = [];

	/** @var array<string, string> */
	private static array $titles = []; // Cache for score board title
	/** @var array<string, string[]> */
	private static array $lines = []; // Cache for score board lines

	private function __construct() {}

	public static function register(PluginBase $plugin): void {
		$plugin->getServer()->getPluginManager()->registerEvents(new ScoreboardSender(), $plugin);
	}

	public static function send(Player $player, string $text): void {
		if((self::$players[$player->getName()] ?? "") == $text) {
			return;
		}

		$lines = explode("\n", $text);
		$title = array_shift($lines);

		$forceSendLines = false;
		if(!array_key_exists($player->getName(), self::$titles) || self::$titles[$player->getName()] != $title) {
			if(array_key_exists($player->getName(), self::$titles)) {
				self::removeScoreboardTitle($player);
			}

			self::createScoreboardTitle($player, $title);
			$forceSendLines = true;
		}

		self::formatLines($lines);
		if(!array_key_exists($player->getName(), self::$lines) || $forceSendLines) {
			self::sendLines($player, $lines);
		} else {
			self::updateLines($player, $lines);
		}

		self::$players[$player->getName()] = $text;
		self::$titles[$player->getName()] = $title;
		self::$lines[$player->getName()] = $lines;
	}

	public static function remove(Player $player): void {
		if(!array_key_exists($player->getName(), self::$players)) {
			return;
		}

		unset(self::$players[$player->getName()]);
		unset(self::$titles[$player->getName()]);
		unset(self::$lines[$player->getName()]);

		self::removeScoreboardTitle($player);
	}

	/**
	 * @param string[] $lines
	 */
	private static function updateLines(Player $player, array $lines): void {
		$cached = self::$lines[$player->getName()] ?? [];
		$changed = $removed = [];
		if(count($lines) == count($cached)) {
			foreach ($lines as $i => $line) {
				if($cached[$i] != $line) {
					$changed[$i] = $line;
				}
			}

			$removed = array_keys($changed);
		} elseif (count($cached) > count($lines)) {
			foreach ($cached as $i => $cachedLine) {
				if(!array_key_exists($i, $lines)) {
					$removed[] = $i;
					continue;
				}

				if($lines[$i] != $cachedLine) {
					$removed[] = $i;
					$changed[$i] = $lines[$i];
				}
			}
		} else {
			foreach ($lines as $i => $line) {
				if(!array_key_exists($i, $cached)) {
					$changed[$i] = $line;
					continue;
				}

				if($cached[$i] != $line) {
					$removed[] = $i;
					$changed[$i] = $line;
				}
			}
		}

		self::removeLines($player, $removed);
		self::sendLines($player, $changed);
	}

	private static function createScoreboardTitle(Player $player, string $title): void {
		$pk = new SetDisplayObjectivePacket();
		$pk->objectiveName = $player->getName();
		$pk->displayName = $title;
		$pk->sortOrder = SetDisplayObjectivePacket::SORT_ORDER_ASCENDING;
		$pk->criteriaName = "dummy";
		$pk->displaySlot = SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR;

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	private static function removeScoreboardTitle(Player $player): void {
		$pk = new RemoveObjectivePacket();
		$pk->objectiveName = $player->getName();

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/**
	 * @param array<int, string> $lines
	 */
	private static function sendLines(Player $player, array $lines): void {
		$pk = new SetScorePacket();
		$pk->type = SetScorePacket::TYPE_CHANGE;
		$pk->entries = array_map(function (int $line) use ($lines, $player): ScorePacketEntry {
			$entry = new ScorePacketEntry();
			$entry->objectiveName = $player->getName();
			$entry->scoreboardId = $entry->score = $line + 1;
			$entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
			$entry->customName = $lines[$line];

			return $entry;
		}, array_keys($lines));

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/**
	 * @param int[] $lines
	 */
	private static function removeLines(Player $player, array $lines): void {
		$pk = new SetScorePacket();
		$pk->type = SetScorePacket::TYPE_REMOVE;
		$pk->entries = array_map(function (int $line) use ($player): ScorePacketEntry {
			$entry = new ScorePacketEntry();
			$entry->objectiveName = $player->getName();
			$entry->scoreboardId = $entry->score = $line + 1;

			return $entry;
		}, $lines);

		$player->getNetworkSession()->sendDataPacket($pk);
	}

	/**
	 * Client removes duplicate lines, so we have to edit them to make them different
	 * Also fixes the format of scoreboard for better look
	 *
	 * @param string[] $lines
	 */
	private static function formatLines(array &$lines): void {
		$used = [];
		foreach ($lines as $i => $line) {
			while (in_array($line, $used)) {
				$line .= "  ";
			}

			$lines[$i] = " $line ";
			$used[] = $line;
		}
	}

	/** @noinspection PhpUnused */
	public function onQuit(PlayerQuitEvent $event): void {
		unset(self::$players[$event->getPlayer()->getName()]);
		unset(self::$titles[$event->getPlayer()->getName()]);
		unset(self::$lines[$event->getPlayer()->getName()]);
	}
}