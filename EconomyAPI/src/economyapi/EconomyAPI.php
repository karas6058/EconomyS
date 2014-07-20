<?php

namespace economyapi;

use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\scheduler\CallbackTask;

use economyapi\event\money\AddMoneyEvent;
use economyapi\event\money\ReduceMoneyEvent;
use economyapi\event\money\SetMoneyEvent;
use economyapi\event\account\CreateAccountEvent;
use economyapi\event\debt\AddDebtEvent;
use economyapi\event\debt\ReduceDebtEvent;

class EconomyAPI extends PluginBase implements Listener{

	/**
	 * @var EconomyAPI
	 */
	private static $obj = null;
	private $path;
	private $money, $bank;
	/**
	 * @var Config
	 */
	private $config;
	/**
	 * @var Config
	 */
	private $command;

	private $schedule, $task, $lastTask;
	private $list, $playerLang, $langRes;
	
	/**
	 * @var int RET_ERROR_1 Unknown error 1
	*/
	const RET_ERROR_1 = -4;
	
	/**
	 * @var int RET_ERROR_2 Unknown error 2
	*/
	const RET_ERROR_2 = -3;
	
	/**
	@var int RET_CANCELLED Task cancelled by event
	*/
	const RET_CANCELLED = -2;
	
	/**
	 * @var int RET_NOT_FOUND Unable to process task due to not found data
	*/
	const RET_NOT_FOUND = -1;
	
	/**
	 * @var int RET_INVALID Invalid amount of data
	*/
	const RET_INVALID = 0;
	
	/**
	 * @var int RET_SUCCESS The task was successful
	*/
	const RET_SUCCESS = 1;
	
	private $langList = array(
		"def" => "Default",
		"en" => "English",
		"ko" => "한국어",
		"user-define" => "User Define"
	);
	
	public static function getInstance(){
		return self::$obj;
	}
	
	public function onLoad(){
		if(!self::$obj instanceof EconomyAPI){
			self::$obj = $this;
		}
		$this->path = $this->getDataFolder();
		$this->schedule = array();
		$this->list = array();
		$this->money = array();
		$this->bank = array();
		$this->task = array();
		$this->lastTask = array(
			"debt" => array(),
			"bank" => array()
		);
		$this->playerLang = array();
		$this->langRes = array();
	}
	
	public function onEnable(){
		@mkdir($this->path);
		
		$this->createConfig();
		$this->scanResources();
		
		file_put_contents($this->path."ReadMe.txt", $this->readResource("ReadMe.txt"));
		if(!is_file($this->path."ScheduleData.dat")){
			file_put_contents($this->path."ScheduleData.dat", serialize(array(
				"debt" => array(),
				"bank" => array()
			)));
		}
		if(!is_file($this->path."PlayerLang.dat")){
			file_put_contents($this->path."PlayerLang.dat", serialize(array()));
		}
		
		$this->schedule = unserialize(file_get_contents($this->path."ScheduleData.dat"));
		$playerLang = unserialize(file_get_contents($this->path."PlayerLang.dat"));
		
		if(isset($playerLang["CONSOLE"])){
			$this->config->set("default-lang", $playerLang["CONSOLE"]);
			$this->config->save();
		}
		
		$this->initializePlayerLang($playerLang);
		$this->getLangFile();
		$cmds = array(
			"setmoney" => "economyapi\\commands\\SetMoneyCommand",
			"seemoney" => "economyapi\\commands\\SeeMoneyCommand",
			"mymoney" => "economyapi\\commands\\MyMoneyCommand",
			"pay" => "economyapi\\commands\\PayCommand",
			"givemoney" => "economyapi\\commands\\GiveMoneyCommand",
			"takedebt" => "economyapi\\commands\\TakeDebtCommand",
			"economys" => "economyapi\\commands\\EconomySCommand",
			"topmoney" => "economyapi\\commands\\TopMoneyCommand",
			"setlang" => "economyapi\\commands\\SetLangCommand",
			"takemoney" => "economyapi\\commands\\TakeMoneyCommand",
			"bank" => "economyapi\\commands\\BankCommand",
			"mydebt" => "economyapi\\commands\\MyDebtCommand",
			"returndebt" => "economyapi\\commands\\ReturnDebtCommand",
			"bankadmin" => "economyapi\\commands\\BankAdminCommand",
			"mystatus" => "economyapi\\commands\\MyStatusCommand"
		);
		$commandMap = $this->getServer()->getCommandMap();
		foreach($cmds as $key => $cmd){
			foreach($this->command->get($key) as $c){
				$commandMap->register("economyapi", new $cmd($this, $c));
			}
		}
		
		// getServer().getPluginManager().registerEvents(this, this);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->convertData();
		$moneyConfig = new Config($this->path."Money.yml", Config::YAML);
		$bankConfig = new Config($this->path."Bank.yml", Config::YAML);
		$this->money = $moneyConfig->getAll();
		$this->bank = $bankConfig->getAll();
		$this->registerList("EconomyAPI");
	}
	
	private function convertData(){
		$cnt = 0;
		if(is_file($this->path."MoneyData.yml")){
			$data = (new Config($this->path."MoneyData.yml", Config::YAML))->getAll();
			$saveData = array();
			foreach($data as $player => $money){
				$saveData["money"][$player] = (float)$money["money"];
				$saveData["debt"][$player] = (float)$money["debt"];
				++$cnt;
			}
			@unlink($this->path."MoneyData.yml");
			$moneyConfig = new Config($this->path."Money.yml", Config::YAML);
			$moneyConfig->setAll($saveData);
			$moneyConfig->save();
		}
		if(is_file($this->path."BankData.yml")){
			$data = (new Config($this->path."BankData.yml", Config::YAML))->getAll();
			$saveData = array();
			foreach($data as $player => $money){
				$saveData["money"][$player] = (float)$money["money"];
				++$cnt;
			}
			@unlink($this->path."BankData.yml");
			$bankConfig = new Config($this->path."Bank.yml", Config::YAML);
			$bankConfig->setAll($saveData);
			$bankConfig->save();
		}
		if($cnt > 0){
			$this->getLogger()->info(TextFormat::AQUA."Converted $cnt data(m) into new format");
		}
	}
	
	private function initializePlayerLang($langData){
		foreach($langData as $player => $lang){
			if(trim($lang) === "") continue;
			$this->playerLang[$player] = $this->langRes[$lang];
		}
	}
	
	private function getSavingPlayerLangData(){
		$ret = array();
		foreach($this->playerLang as $player => $lang){
			$ret[$player] = $lang["language"];
		}
		return $ret;
	}
	
	private function createConfig(){
		$this->config = new Config($this->path."economy.properties", Config::PROPERTIES, yaml_parse($this->readResource("config.yml")));
		$this->command = new Config($this->path."command.yml", Config::YAML, yaml_parse($this->readResource("command.yml")));
	}
	
	private function scanResources(){
		foreach($this->getResources() as $resource){
			$s = explode("\\", $resource);
			$res = $s[count($s) - 1];
			if(substr($res, 0, 5) === "lang_"){
				$this->langRes[substr($res, 5, -5)] = get_object_vars(json_decode($this->readResource($res)));
			}
		}
	}
	
	/**
	 * @param string $name
	*/
	public function registerList($name){
		if(trim($name) === ""){
			return false;
		}
		if(in_array($name, $this->list)){
			return false;
		}else{
			$this->list[] = $name;
			return true;
		}
	}	
	
	/**
	 * @param string $name
	*/
	public function unregisterList($name){
		foreach($this->list as $key => $n){
			if($n === $name){
				unset($this->list[$key]);
				return true;
			}
		}
		return false;
	}
	
	/**
	 * @return string[]
	*/
	public function getList(){
		return $this->list;
	}
	
	/**
	 * @return object
	*/
	public function getConfigurationValue($key, $default = false){
		if($this->config->exists($key)){
			return $this->config->get($key);
		}
		return $default;
	}
	
	private function readResource($res){
		$path = $this->getFile()."resources/".$res;
		$resource = $this->getResource($res);

		if(!is_resource($resource)){
			$this->getLogger()->debug("Tried to load unknown resource ".TextFormat::AQUA.$res.TextFormat::RESET);
			return false;
		}
		return fread($resource, filesize($path));
	}
	
	private function getLangFile(){
		$lang = $this->config->get("default-lang");
		if(($resource = $this->readResource("lang_".$lang.".json")) !== false){
			$vars = get_object_vars(json_decode($resource));
			$this->playerLang["CONSOLE"] = $vars;
			$this->playerLang["RCON"] = $vars;
			$this->getLogger()->info(TextFormat::GREEN.$this->getMessage("language-set", "CONSOLE", array($this->langList[$lang], "%2", "%3", "%4")));
		}elseif($lang === "user-define"){
			$vars = (new Config($this->getDataFolder()."language.properties", Config::PROPERTIES, get_object_vars(json_decode($this->readResource("lang_def.json")))))->getAll();
			$this->playerLang["CONSOLE"] = $vars;
			$this->playerLang["RCON"] = $vars;
			$this->getLogger()->info(TextFormat::GREEN.$this->getMessage("language-set", "CONSOLE", array("User Define", "%2", "%3", "%4")));
		}else{
			$vars = get_object_vars(json_decode($this->readResource("lang_def.json")));
			$this->playerLang["CONSOLE"] = $vars;
			$this->playerLang["RCON"] = $vars;
			$this->getLogger()->info(TextFormat::GREEN.$this->getMessage("language-set", "CONSOLE", array($this->langList[$lang], "%2", "%3", "%4")));
		}
	}
	
	/**
	 * @param string $lang
	 *
	 * @return bool
	*/
	public function setLang($lang, $target = "CONSOLE"){
		if(($resource = $this->readResource("lang_".$lang.".json")) !== false){
			$this->playerLang[$target] = get_object_vars(json_decode($resource));
			return true;
		}else{
			foreach($this->langList as $key => $l){
				if(strtolower($lang) === strtolower($l)){
					if(($resource = $this->langList($key)) !== false){
						$this->playerLang[$target] = $resource;
						return $l;
					}else{
						return false;
					}
				}
			}
			return false;
		}
	}
	
	/**
	 * @return array
	*/
	public function getLangList(){
		return $this->langList;
	}
	
	/**
	 * @return array
	*/
	public function getLangResource(){
		return $this->langRes;
	}
	
	/**
	 * @param string|Player $player
	 *
	 * @return string|boolean
	*/
	public function getPlayerLang($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(isset($this->playerLang[$player])){
			return $this->playerLang[$player];
		}else{
			return false;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 * @param bool $force
	 * @param string $issuer
	 *
	 * @return int
	*/
	public function addDebt($player, $amount, $force = false, $issuer = "external"){
		if($amount <= 0){
			return self::RET_INVALID;
		}
		if($player instanceof Player){
			$player = $player->getName();
		}
		$amount = round($amount, 2);
		if(isset($this->money["debt"][$player])){
			$debt = $this->money["debt"][$player];
			
			if(($debt + $amount > $this->config->get("debt-limit")) and $force === false){
				return self::RET_ERROR_1;
			}
			if((($amount > $this->config->get("once-debt-limit")) and $force === false)){
				return self::RET_ERROR_2;
			}
			$ev = new AddDebtEvent($this, $player, $amount, $issuer);
			$this->getServer()->getPluginManager()->callEvent($ev);
			if($force === false and $ev->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->money["debt"][$player] += $amount;
			
			if(!isset($this->task[$player]["debt"])){
				$this->task[$player]["debt"] = $this->getServer()->getScheduler()->scheduleDelayedTask( new CallbackTask(array($this, "debtScheduler"), array($player)), ($this->config->get("time-for-increase-debt") * 1200))->getTaskId();
				$this->schedule["debt"][$player] = ($this->config->get("time-for-increase-debt") * 1200);
			}
			$this->lastTask["debt"][$player] = time();
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 * @param bool $force
	 * @param string $issuer
	 *
	 * @return int
	*/
	public function reduceDebt($player, $amount, $force = false, $issuer = "external"){
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		$amount = round($amount, 2);
		if(isset($this->money["debt"][$player])){
			$debt = $this->money["debt"][$player];
			$money = $this->money["money"][$player];
			if($amount <= 0 or $debt < $amount or $money < $amount){
				return self::RET_INVALID;
			}
			$ev = new ReduceDebtEvent($this, $player, $amount, $issuer);
			$this->getServer()->getPluginManager()->callEvent($ev);
			if($force === false and $ev->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->money["debt"][$player] -= $amount;
			if($this->money["debt"][$player] <= 0){
				$this->getServer()->getScheduler()->cancelTask($this->task[$player]["debt"]);
				$this->lastTask["debt"][$player] = null;
				$this->schedule["debt"][$player] = null;
				unset($this->task[$player]["debt"], $this->schedule["debt"][$player], $this->lastTask["debt"][$player]);
			}
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 * @param bool $force
	 * @param string $issuer
	 *
	 * @return int
	*/
	public function addBankMoney($player, $amount, $force = false, $issuer = "external"){
		if($amount <= 0){
			return self::RET_INVALID;
		}
		if($player instanceof Player){
			$player = $player->getName();
		}
		$amount = round($amount, 2);
		if(isset($this->bank["money"][$player])){
			$ev = new \economyapi\event\bank\AddMoneyEvent($this, $player, $amount, $issuer); // I have to declare package because there's same named class at \economyapi\event\money\AddMoneyEvent
			$this->getServer()->getPluginManager()->callEvent($ev);
			if($force === false and $ev->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->bank["money"][$player] += $amount;
			
			if(!isset($this->task[$player]["bank"])){
				$this->schedule["bank"][$player] = ($this->config->get("time-for-increase-money") * 1200);
				$this->task[$player]["bank"] = $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "bankScheduler"), array($player)), $this->config->get("time-for-increase-money") * 1200)->getTaskId();
			}
			$this->lastTask["bank"][$player] = time();
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 * @param bool $force
	 * @param string $issuer
	 *
	 * @return int
	*/
	public function reduceBankMoney($player, $amount, $force = false, $issuer = "external"){
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		$amount = round($amount, 2);
		if(isset($this->bank["money"][$player])){
			if($amount <= 0 or $amount > $this->bank["money"][$player]){
				return self::RET_INVALID;
			}
			$ev = new \economyapi\event\bank\ReduceMoneyEvent($this, $player, $amount, $issuer);
			$this->getServer()->getPluginManager()->callEvent($ev);
			if($force === false and $ev->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->bank["money"][$player] -= $amount;
			if($this->bank["money"][$player] <= 0){
				$this->getServer()->getScheduler()->cancelTask($this->task[$player]["bank"]);
				$this->schedule["bank"][$player] = null;
				$this->lastTask["bank"][$player] = null;
				unset($this->schedule["bank"][$player], $this->lastTask["bank"][$player], $this->task[$player]["bank"]);
			}
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @return array
	*/
	public function getAllMoney(){
		return $this->money;
	}
	
	/**
	 * @return array
	*/
	public function getAllBankMoney(){
		return $this->bank;
	}
	
	/**
	 * @param string $key
	 * @param Player|string $player
	 * @param array $value
	 *
	 * @return string
	*/
	public function getMessage($key, $player = "CONSOLE", array $value = array("%1", "%2", "%3", "%4")){
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(isset($this->playerLang[$player][$key])){
			return str_replace(array("%1", "%2", "%3", "%4"), array($value[0], $value[1], $value[2], $value[3]), $this->playerLang[$player][$key]);
		}else{
			return "Couldn't find message resource";
		}
	}
	
	/**
	 * @param Player|string $player
	 *
	 * @return boolean
	*/
	public function accountExists($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		return isset($this->money["money"][$player]);
	}
	
	/**
	 * @param Player|string $player
	 * @param float $default_money
	 * @param float $default_debt
	 * @param float $default_bank_money
	 *
	 * @return boolean
	*/
	public function createAccount($player, $default_money = false, $default_debt = false, $default_bank_money = false, $force = false){
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(!isset($this->money["money"][$player])){
			$this->getServer()->getPluginManager()->callEvent(($ev = new CreateAccountEvent($this, $player, $default_money, $default_debt, $default_bank_money, "EconomyAPI")));
			if(!$ev->isCancelled() and $force === false){
				$this->money["money"][$player] = ($default_money === false ? $this->config->get("default-money") : $default_money);
				$this->money["debt"][$player] = ($default_debt === false ? $this->config->get("default-debt") : $default_debt);
				$this->bank["money"][$player] = ($default_bank_money === false ? $this->config->get("default-bank-money") : $default_bank_money);
				return true;
			}
		}
		return false;
	}
	
	/**
	 * @param Player|string $player
	 *
	 * @return boolean
	*/
	public function removeAccount($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(isset($this->money["money"][$player])){
			$this->money["money"][$player] = null;
			$this->money["debt"][$player] = null;
			$this->bank["money"][$player] = null;
			unset($this->money["money"][$player], $this->money["debt"][$player], $this->bank["money"][$player]);
			return true;
		}
		return false;
	}
	
	/**
	 * @param Player|string $player
	 *
	 * @return boolean
	*/
	public function bankAccountExists($player){
		if($player instanceof Player){
			$player = $player->getName();
		}
		return isset($this->bank["money"][$player]);
	}
	
	/**
	 * @param Player|string $player
	
	 * @return boolean|float
	*/
	public function myMoney($player){ // To identify the result, use '===' operator
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(!isset($this->money["money"][$player])){
			return false;
		}
		return $this->money["money"][$player];
	}
	
	/**
	 * @param Player|string $player
	
	 * @return boolean|float
	*/
	public function myDebt($player){ // To identify the result, use '===' operator
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(!isset($this->money["debt"][$player])){
			return false;
		}
		return $this->money["debt"][$player];
	}
	
	/**
	 * @param Player|string $player
	 *
	 * @return boolean|float
	*/
	public function myBankMoney($player){ // To identify the result, use '===' operator
		if($player instanceof Player){
			$player = $player->getName();
		}
		if(!isset($this->bank["money"][$player])){
			return false;
		}
		return $this->bank["money"][$player];
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 *
	 * @return int
	*/
	public function addMoney($player, $amount, $force = false, $issuer = "external"){
		if($amount <= 0 or !is_numeric($amount)){
			return self::RET_INVALID;
		}
		
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		$amount = round($amount, 2);
		if(isset($this->money["money"][$player])){
			$event = new AddMoneyEvent($this, $player, $amount, $issuer);
			$this->getServer()->getPluginManager()->callEvent($event);
			if($force === false and $event->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->money["money"][$player] += $amount;
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $amount
	 *
	 * @return int
	*/
	public function reduceMoney($player, $amount, $force = false, $issuer = "external"){
		if($amount <= 0 or !is_numeric($amount)){
			return self::RET_INVALID;
		}
		
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		$amount = round($amount, 2);
		if(isset($this->money["money"][$player])){
			if($this->money["money"][$player] - $amount < 0){
				return self::RET_INVALID;
			}
			$event = new ReduceMoneyEvent($this, $player, $amount, $issuer);
			$this->getServer()->getPluginManager()->callEvent($event);
			if($force === false and $event->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->money["money"][$player] -= $amount;
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	/**
	 * @param Player|string $player
	 * @param float $money
	 *
	 * @return int
	*/
	public function setMoney($player, $money, $force = false, $issuer = "external"){
		if($money < 0 or !is_numeric($money)){
			return self::RET_INVALID;
		}
		
		if($player instanceof Player){
			$player = $player->getName();
		}
		
		$money = round($money, 2);
		if(isset($this->money["money"][$player])){
			$ev = new SetMoneyEvent($this, $player, $money, $issuer);
			$this->getServer()->getPluginManager()->callEvent($ev);
			if($force === false and $ev->isCancelled()){
				return self::RET_CANCELLED;
			}
			$this->money["money"][$player] = $money;
			return self::RET_SUCCESS;
		}else{
			return self::RET_NOT_FOUND;
		}
	}
	
	public function onDisable(){
		$moneyConfig = new Config($this->path."Money.yml", Config::YAML);
		$bankConfig = new Config($this->path."Bank.yml", Config::YAML);
		$moneyConfig->setAll($this->money);
		$moneyConfig->save();
		$bankConfig->setAll($this->bank);
		$bankConfig->save();
		file_put_contents($this->path."ScheduleData.dat", serialize($this->schedule));
		file_put_contents($this->path."PlayerLang.dat", serialize($this->getSavingPlayerLangData()));
	}
	
	public function onQuitEvent(PlayerQuitEvent $event){
		$username = $event->getPlayer()->getName();
		if(isset($this->task[$username])){
			$now = time();
			if(isset($this->task[$username]["debt"])){
				$this->schedule["debt"][$username] -= (($now - $this->lastTask["debt"][$username]) * 20);
				$this->getServer()->getScheduler()->cancelTask($this->task[$username]["debt"]);
				unset($this->task[$username]["debt"]);
			}
			if(isset($this->task[$username]["bank"])){
				$this->schedule["bank"][$username] -= (($now - $this->lastTask["bank"][$username]) * 20);
				$this->getServer()->getScheduler()->cancelTask($this->task[$username]["bank"]);
				unset($this->task[$username]["bank"]);
			}
		}
	}
	
	public function onJoinEvent(PlayerJoinEvent $event){
		$username = $event->getPlayer()->getName();
		if(!isset($this->money["money"][$username])){
			$this->getServer()->getPluginManager()->callEvent(($ev = new CreateAccountEvent($this, $username, $this->config->get("default-money"), $this->config->get("default-debt"), null, "EconomyAPI")));
			$this->money["money"][$username] = $ev->getDefaultMoney();
			$this->money["debt"][$username] = $ev->getDefaultDebt();
		}
		if(!isset($this->bank["money"][$username])){
			$this->getServer()->getPluginManager()->callEvent(($ev = new CreateAccountEvent($this, $username, null, null, $this->config->get("default-bank-money"), "EconomyAPI")));
			$this->bank["money"][$username] = $ev->getDefaultBankMoney();
		}
		if(!isset($this->playerLang[$username])){
			$this->setLang($this->config->get("default-lang"), $username);
		}
		if(isset($this->schedule["debt"][$username])){
			$this->task[$username]["debt"] = $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "debtScheduler"), array($username)), ($this->schedule["debt"][$username]))->getTaskId();
			$this->lastTask["debt"][$username] = time();
		}
		if(isset($this->schedule["bank"][$username])){
			$this->task[$username]["bank"] = $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "bankScheduler"), array($username)), ($this->schedule["bank"][$username]))->getTaskId();
			$this->lastTask[$username]["bank"] = time();
		}
	}
	
	public function bankScheduler($username){
		$player = $this->getServer()->getPlayerExact($username);
		if(!$player instanceof Player){
			return;
		}
		if($this->bank["money"][$username] <= 0){
			$this->lastTask["bank"][$username] = null;
			$this->schedule["bank"][$username] = null;
			unset($this->lastTask["bank"][$username], $this->schedule["bank"][$username]);
			return;
		}
		$per = $this->getConfigurationValue("bank-increase-money-rate");
		$increase = ($this->myBankMoney($player) * ($per / 100));
		$this->addBankMoney($player, $increase, true, "bankScheduler");
		$player->sendMessage($this->getMessage("bank-credit-increase", $player->getName()));
		$this->lastTask["bank"][$username] = time();
		$this->task[$username]["bank"] = $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "bankScheduler"), array($username)), ($this->config->get("time-for-increase-money") * 1200))->getTaskId();
	}
	
	public function debtScheduler($username){
		$player = $this->getServer()->getPlayerExact($username);
		if(!$player instanceof Player){
			return;
		}
		if($this->money["debt"][$username] <= 0){
			$this->lastTask["debt"][$username] = null;
			$this->schedule["debt"][$username] = null;
			unset($this->lastTask["debt"][$username], $this->schedule["debt"][$username]);
			return;
		}
		$per = $this->getConfigurationValue("percent-of-increase-debt");
		$increase = ($this->myDebt($player) * ($per / 100));
		$this->addDebt($player, $increase, true, "DebtScheduler");
		$player->sendMessage($this->getMessage("debt-increase", $player, array($this->myDebt($player), "", "", "")));
		$this->lastTask["debt"][$username] = time();
		$this->task[$username]["debt"] = $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask(array($this, "debtScheduler"), array($username)), ($this->config->get("time-for-increase-debt") * 1200))->getTaskId();
	} 
	
	/**
	 * @return string
	*/
	public function __toString(){
		return "EconomyAPI (accounts:".count($this->money).", bank: ".count($this->money).")";
	}
}