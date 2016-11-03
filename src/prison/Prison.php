<?php
namespace prison;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\Plugin;
use pocketmine\utils\TextFormat as Text;
use pocketmine\utils\Config;
use pocketmine\Player;

use prison\economy\Economy;
use prison\command\RankUp;
use prison\messages\Library;
use prison\event\listener\PlayerEventListener;
use prison\event\listener\SignController;
use prison\signs\PrisonSign as Sign;

use _64FF00\PurePerms\PurePerms;
use _64FF00\PurePerms\PPGroup;

class Prison extends PluginBase {

	/** @var PurePerms */
	protected $pureperms;
	/** @var Plugin */
	protected $economy;
	/** @var Library */
	protected $library;

	/** @var array $groups */
	protected $groups = [];
  
  public function onEnable(){
    $this->getLogger()->info("Loading...");
    Sign::init();
    @mkdir($this->getDataFolder());

    $this->saveDefaultConfig();

    // Load dependencies such as Economy and PurePerms

    // PurePerms
    $purePerms = $this->getServer()->getPluginManager()->getPlugin("PurePerms");
    if($purePerms === null or $purePerms->isEnabled() === false){
    	$this->getServer()->getPluginManager()->disablePlugin($this);
    	$this->getLogger()->critical("PurePerms was not found or/and is disabled!");
    	return;
    } else {
    	$this->getLogger()->info("Permission plugin: ".Text::GOLD.$purePerms->getFullName());
    }
    // Economy
    $economy = new Economy($this, $this->getConfig()->get('preferred-economy'));

    if(!$economy->isLoaded()){
    	$this->getLogger()->critical("One of economy plugins (EconomyAPI, MassiveEconomy, PocketMoney, GoldStd) was not found or/and is disabled!");
    	$this->getServer()->getPluginManager()->disablePlugin($this);
    	return;
    }
    $this->economy = $economy;
    $this->pureperms = $purePerms;

    // Load groups which this plugin will use as ranks
    $groups = $this->getConfig()->get('groups');
    $i = 0;
    foreach($groups as $group => $price){
    	if($ppgroup = $purePerms->getGroup($group)){
    		$this->groups[$i] = [
    			"group" => $ppgroup,
    			"price" => $price
    		];
    		$i++;
    	} else {
    		$this->getLogger()->warning("Group: '".$group."' does not exist! You must create it manually.");
    	}
    }

    $this->getLogger()->info("--- Loaded Groups ---");
    foreach($this->groups as $i => $g) $this->getLogger()->info(($i + 1).". ".$g['group']->getName()." : ".Text::GOLD.$economy->formatMoney($g['price']));
    $this->getLogger()->info("---------------------");

    $this->library = new Library($this, $this->getConfig()->get('prefix'));
    
    // Register events
    $this->getServer()->getPluginManager()->registerEvents(new PlayerEventListener($this), $this);
    $this->getServer()->getPluginManager()->registerEvents(new SignController($this), $this);
    
    // Load signs
    $signs = (new Config($this->getDataFolder() . "signs.json", Config::JSON, []))->getAll(); // I'm not going to use this object anymore
    if(!empty($signs))
    {
    	foreach($signs as $sign) {
    		Sign::loadSign($sign);
    	}
    	$this->getLogger()->info("Loaded {count(Sign::getAll()} signs");
    }
		$this->registerCommands();  
  }

  public function onDisable(){
    $signs = [];
    foreach(Sign::getAll() as $sign) {
      $signs[] = [
        "x" => $sign->getX(),
        "y" => $sign->getY(),
        "z" => $sign->getZ(),
        "level" => $sign->getLevel()
        ];
    }
    (new Config($this->getDataFolder() . "signs.json", Config::JSON, $signs))->save();
  	$this->getLogger()->info("Disabled!");
  }

  public function registerCommands(){
  	 $this->getServer()->getCommandMap()->register("Prison", new RankUp($this, 'rankup', 'Rank-up to new rank', '/rankup', ['ru', 'ranku', 'rup']));
  }

  /**
   * @param Player $player
   * @return PPGroup|Null
   */
  public function getPlayerGroup(Player $player){
  	return $this->pureperms->getUserDataMgr()->getGroup($player);
  }

  /**
   * @param PPGroup $group
   * @return bool
   */
  public function isPrisonGroup(PPGroup $group) : bool {
  	foreach($this->groups as $groupData) {
      if($groupData["group"] === $group) return true;
    }
    return false;
  }

  public function getGroupData(PPGroup $group) : array {
    foreach($this->groups as $groupData) {
      if($groupData["group"] === $group) return $groupData;
    }
  }

  /**
   * @param Player $player
   * @param PPGroup $group
   */
  public function setPlayerGroup(Player $player, PPGroup $group){
  	$this->pureperms->getUserDataMgr()->setGroup($player, $group, null);
  }

  /**
   * @param Player $player
   * @return bool
   */
  public function rankup(Player $player){
    $g = $this->getPlayerGroup($player);
    if(!$this->isPrisonGroup($g)){
      $player->sendMessage($this->library->getMessage("not_prison_group"));
    } else {
      $ng = $this->getNextGroup($g);
      if($ng instanceof PPGroup){
      	$pmoney = $this->economy->getMoney($player);
      	if($pmoney >= ($price = $this->getGroupPrice($ng))){
      		$this->economy->takeMoney($player, $price);
      		$this->setPlayerGroup($player, $ng);
      		$player->sendMessage($this->library->getMessage('ranked_up', $ng->getName(), $this->economy->formatMoney($price)));
      		if($this->getConfig()->get('broadcast-on-rankup')) $this->getServer()->broadcastMessage($this->library->getMessage('ranked_up_broadcast', $player->getName(), $ng->getName(), $this->economy->formatMoney($price)));
      		return true;
      	} else {
      		$player->sendMessage($this->library->getMessage('not_enough_money', $this->economy->formatMoney($price), $this->economy->formatMoney($pmoney)));
      	}
      } else {
      	$player->sendMessage($this->library->getMessage('highest_rank'));
      }
    }
    return false;
  }

  /**
   * @param PPGroup $group
   * @return int
   */
  public function getGroupPrice(PPGroup $group) : int {
  	$gp = $this->getGroupData($group);
    return $gp["price"] ?? 0;
  }

  /**
   * @param PPGroup $group
   * @return PPGroup|Null
   */
  public function getNextGroup(PPGroup $group) {
  	$n = false;
    foreach($this->groups as $d) {
      if($n) return $d["group"];
      if($d["group"] === $group) $n = true;
    }
    return null;
  }

  /**
   * @param PPGroup $group
   * @return PPGroup|null
   */
  public function getPreviousGroup(PPGroup $group) {
    if(isset($this->groups[($c = array_search($group, $this->groups, true)) - 1]))
      return $this->groups[$c - 1]['group'];
    else
      return null;
  }
	
  public function getLibrary() : Library {
  	return $this->library;
  }

}
