<?php
namespace Catali\Mailer;
use \TymFrontiers\MySQLDatabase,
    \TymFrontiers\Validator;
use function \Catali\get_constant;
use function \Catali\get_dbserver;
use function \Catali\get_database;
use function \Catali\get_dbuser;

class Profile {
    use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='id';
  protected static $_db_name;
  protected static $_table_name = "mailer_profiles";
  protected static $_db_fields = [
    "id",
    "address", 	
    "name",	
    "surname",	
    "_created"
  ];
  public $id;
  public $address;
  public $name = NULL;
  public $surname = NULL;
  protected $_created;

  public $errors = [];

  function __construct (mixed $address = "", string $name = "", string $surname = "", $conn = false) {
    // server name
    if (!$srv = get_constant("PRJ_EMAIL_SERVER")) {
      throw new \Exception("Email server not defined", 1);
    }
    // database name
    if (!$db_name = get_database($srv, "email")) {
      throw new \Exception("Email database name not set", 1);
    } 
    self::$_db_name = $db_name;
    // database server
    if (!$db_server = get_dbserver($srv)) {
      throw new \Exception("Email database-server not set", 1);
    } 
    
    // Check @var $conn
    if ($conn && $conn instanceof MySQLDatabase && $conn->getServer() == $db_server ) {
      self::_setConn($conn);
    } else {
      global $session;
      // database user
      if (!$db_user = get_dbuser($srv, $session->access_group())) {
        throw new \Exception("Email database-user not set for [{$session->access_group()}]", 1);
      }
      // set database connection
      $conn = new MySQLDatabase($db_server, $db_user[0], $db_user[1], $db_name);
      self::_setConn($conn);
    }

    if (\is_array($address)) {
      $this->_check($this->propArray($address), $name, $surname);
    } else {
      if (!empty($address) && !empty($name)) {
        $this->_check($address, $name, $surname);
      }
    }
  }

  private function _check(mixed $prop, string $name, string $surname = ""):void {
    $conn =& self::$_conn;
    if (\is_array($prop)) {
      if (!$found = self::findBySql("SELECT * FROM :db:.:tbl: WHERE `address` = '{$prop["address"]}' LIMIT 1")) {
        $this->put($prop["address"], $prop["name"], $prop["surname"]);
      } else {
        foreach ($found[0] as $prop => $val) {
          $this->$prop = $val;
        }
      }
    } else {
      $valid = new Validator;
      if ($address = $valid->email($prop, ["email", "email"])) {
        if ($found = self::findBySql("SELECT * FROM :db:.:tbl: WHERE `address` = '{$address}' LIMIT 1")) {
          foreach ($found[0] as $prop => $val) {
            $this->$prop = $val;
          }
        } else {
          if ($name = $valid->name($name, ["name", "name"])) {
            $this->put($address, $name, $surname);
          }
        }
      }
    }
  }
  public function propArray (array $props):array {
    $return = ["address" => "", "name"=> "", "surname"=>""];
    $valid = new Validator;
    if (empty($props["email"]) || !$return["address"] = $valid->email($props["email"], ["email", "email"])) {
      throw new \Exception("Improper value for array key [email]", 1);
    } if (empty($props["name"]) || !$return["name"] = $valid->name($props["name"], ["name", "name"])) {
      throw new \Exception("Improper value for array key [name]", 1);
    }
    $return["surname"] = (!empty($props["surname"]) && $surname = $valid->text($props["surname"], ["surname", "text", 2, 28])) ? $surname : "";
    return $return;
  }
  public static function get (string $address) {
    self::_checkConn();
    $conn =& self::$_conn;
    if ($found = self::findBySql("SELECT * FROM :db:.:tbl: WHERE `address` = '{$conn->escapeValue($address)}' LIMIT 1")) {
      return $found[0];
    }
    return false;
  }
  public function put (string $address, string $name, string $surname = ""):bool {
    self::_checkConn();
    $valid = new Validator();
    $this->address = $valid->email($address, ["email", "email"]);
    $this->name = $valid->name($name, ["name", "name"]);
    if ((bool)$this->address && (bool)$this->name ) {
      $this->surname = $valid->name($surname, ["name","name"]) ? $surname : "";
      return $this->_create();
    }
    return false;
  }
  public function getAddress ():string {
    return !empty($this->name)
      ? "{$this->name}" . (!empty($this->surname) ? " {$this->surname}" : "") . " <{$this->address}>"
      : (!empty($this->address) ? $this->address : "");
  }
}