<?php
declare(strict_types=1);

namespace customiesdevs\customies;

use customiesdevs\customies\block\CustomiesBlockFactory;
use customiesdevs\customies\item\CustomiesItemFactory;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\ItemRegistryPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use function array_merge;
use function defined;
use function hash;
use function method_exists;
use function strcmp;
use function usort;

final class CustomiesListener implements Listener {

	private ?ItemRegistryPacket $cachedItemComponentPacket = null;
	/** @var ItemTypeEntry[] */
	private array $cachedItemTable = [];
	/** @var BlockPaletteEntry[] */
	private array $cachedBlockPalette = [];
	private Experiments $experiments;

	public function __construct() {
		$this->experiments = new Experiments([
			// "data_driven_items" is required for custom blocks to render in-game. With this disabled, they will be
			// shown as the UPDATE texture block.
			"data_driven_items" => true,
		], true);
	}

	public function onDataPacketSend(DataPacketSendEvent $event): void {
		foreach($event->getPackets() as $packet){
			if($packet instanceof BiomeDefinitionListPacket) {
				foreach($event->getTargets() as $session){
					if(method_exists($session, "getProtocolId")) {
						// https://github.com/NetherGamesMC/PocketMine-MP/blob/9bb498806586517473012b2e7fab12402dcd3cd5/src/network/mcpe/handler/PreSpawnPacketHandler.php#L117-L120
						$protocolId = $session->getProtocolId();
						if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60) {
							return;
						}
						// ItemComponentPacket needs to be sent after the BiomeDefinitionListPacket.
						if($this->cachedItemComponentPacket === null) {
							// Wait for the data to be needed before it is actually cached. Allows for all blocks and items to be
							// registered before they are cached for the rest of the runtime.
							$this->cachedItemComponentPacket = ItemRegistryPacket::create(CustomiesItemFactory::getInstance()->getItemTableEntries());
						}
						$session->sendDataPacket($this->cachedItemComponentPacket);
					}
					break;//expect there is only one recipient
				}
			} elseif($packet instanceof StartGamePacket) {
				$protocolId = ProtocolInfo::CURRENT_PROTOCOL;
				foreach($event->getTargets() as $session){
					if(method_exists($session, "getProtocolId")) {
						$protocolId = $session->getProtocolId();
					}
					break;//expect there is only one recipient
				}
				$packet->levelSettings->experiments = $this->experiments;

				if(!isset($this->cachedBlockPalette[$protocolId])){
					$customEntries = CustomiesBlockFactory::getInstance()->getBlockPaletteEntries($protocolId);
					$protocol1_26_50 = defined(ProtocolInfo::class . '::PROTOCOL_1_26_50') ? ProtocolInfo::PROTOCOL_1_26_50 : 2193;
					if($protocolId >= $protocol1_26_50 && method_exists(StaticPacketCache::getInstance(), "getBlockPaletteEntries")){
						// Since 1.26.50 the vanilla data-driven blocks (double slabs etc.) are sent in this palette too, so
						// they have to be merged in: dropping them would leave the client's palette missing those names
						// while the server's BlockStateDictionary still has their states, shifting every network block
						// runtime ID that comes after them.
						$merged = array_merge(
							StaticPacketCache::getInstance()->getBlockPaletteEntries(),
							$customEntries
						);
						usort($merged, static function(BlockPaletteEntry $a, BlockPaletteEntry $b): int {
							return strcmp(hash("fnv164", $a->getName()), hash("fnv164", $b->getName()));
						});
						$this->cachedBlockPalette[$protocolId] = $merged;
					} else {
						$this->cachedBlockPalette[$protocolId] = $customEntries;
					}
				}

				$packet->blockPalette = $this->cachedBlockPalette[$protocolId];
			} elseif($packet instanceof ResourcePackStackPacket) {
				$packet->experiments = $this->experiments;
			}
		}
	}
}
