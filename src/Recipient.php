<?php
namespace Catali\Email;
use \TymFrontiers\MySQLDatabase,
    \TymFrontiers\Validator;
use function \Catali\get_constant;
use function \Catali\get_dbserver;
use function \Catali\get_database;
use function \Catali\get_dbuser;

class Recipient {
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='id';
  protected static $_db_name;
  protected static $_table_name = "email_recipients";
  protected static $_db_fields = [
    "id",
    "email",
    "mlist", 
    "type",
    "address", 	
    "name",	
    "surname",	
    "_created"
  ];
  public $id;
  public $email;
  public $type = "to";
  public $address;
  public $mlist = NULL;
  public $name = NULL;
  public $surname = NULL;
  protected $_created;

  public $errors = [];

  function __construct (mixed $refid = 0, mixed $user = null, string $type = "to", string $mlist = "", $conn = false) {
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

    if (!empty($type)) {
      $this->type = $type;
    } if ((new Validator)->pattern($refid, ["code","pattern", "/^421([0-9]{8,11})$/"]) && !empty($user)) {
      // refid = Email code
      $email = (\is_array($user) && !empty($user["email"])) ? $user["email"] : (
        (new Validator)->email($user, ["email", "email"]) ? $user : false
      );
      $conn =& self::$_conn;
      if ($email){
        if ($found = self::findBySql("SELECT * FROM :db:.:tbl: WHERE email='{$conn->escapeValue($refid)}' AND `address` = '{$conn->escapeValue($email)}' AND `type` = '{$conn->escapeValue($this->type)}' LIMIT 1")) {
          foreach ($found[0] as $prop => $val) {
            $this->$prop = $val;
          }
        } else {
          // create it
          $this->put($refid, $user, (!empty($type) ? $type : "to"), $mlist);
        }
      } 
    } else {
      // find
      if ((int)$refid && $found = self::findById((int)$refid)) {
        foreach ($found as $prop => $val) {
          $this->$prop = $val;
        }
      }
    }

  }
  public static function get (int $id) {
    return self::findById($id);
  }
  public function put (string $email, mixed $address, string $type = "to", string $mlist = ""):bool {
    self::_checkConn();
    $valid = new Validator();
    if (\is_array($address)) {
      if (!empty($address["email"])) $this->address = $valid->email($address["email"], ["email", "email"]);
      if (!empty($address["name"])) $this->name = $valid->name($address["name"], ["name", "name"]);
      if (!empty($address["surname"])) $this->surname = $valid->text($address["surname"], ["text", "text", 2, 28]);
    } if (\is_string($address) && !empty($address)) {
      $this->address = $valid->email($address, ["email", "email"]);
    }
    $this->email = $valid->pattern($email, ["email","pattern", "/^421([0-9]{8,11})$/"]);
    $this->type = $valid->option($type, ["type","option", ["to", "cc", "bcc", "reply-to"]]);
    if (!empty($mlist)) $this->mlist = $valid->pattern($mlist, ["mlist","pattern", "/^218([0-9]{8,11})$/"]);
    return $this->_create();
  }
  public function getAddress ():string {
    return !empty($this->name)
      ? "{$this->name}" . (!empty($this->surname) ? " {$this->surname}" : "") . " <{$this->address}>"
      : $this->address;
  }
  public static function propagate (string $address, string $name, mixed $surname, string $type = "to", $mlist = null ):Recipient {
    $return = new self;
    $valid = new Validator;
    if (!$valid->email($address, ["address", "email"])) {
      throw new \Exception("Invalid value passed for [address]", 1);
    } if (!$valid->name($name, ["name", "name"])) {
      throw new \Exception("Invalid value passed for [name]", 1);
    } if (!$valid->option($type, ["type", "option", ["to", "cc", "bcc", "reply-to"]])) {
      throw new \Exception("Invalid value passed for [type]", 1);
    }
    $return->address = $address;
    $return->name = $name;
    $return->surname = $surname;
    $return->type = $type;
    $return->mlist = $mlist;
    return $return;
  }
}