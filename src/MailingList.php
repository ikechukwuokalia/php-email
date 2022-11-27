<?php
namespace IO;
use \TymFrontiers\Data,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\MultiForm,
    \TymFrontiers\Validator,
    \TymFrontiers\InstanceError;

class MailingList {
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;
      
  protected static $_primary_key='code';
  protected static $_db_name;
  protected static $_table_name = "mailing_lists";
  protected static $_db_fields = [
    "code",
    "user", 	
    "title",	
    "description",	
    "_author",
    "_created"
  ];
  public $code;
  public $user;
  public $title;
  public $description;
  protected $_author;
  protected $_created;

  public $errors = [];

  function __construct ($conn = false) {
    // server name
    if (!$srv = get_constant("PRJ_EMAIL_SERVER")) {
      throw new \Exception("Email server not defined", 1);
    }
    // database name
    if (!$db_name = get_database("email", $srv)) {
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
  }
  public static function get (string $code) {
    return self::findById($code);
  }
  public static function make (string $user, string $title, string $description, string $code = "") {
    self::_checkConn();
    if (!self::$_db_name = get_constant("MYSQL_MSG_DB")) {
      throw new \Exception("Messaging Database 'MYSQL_MSG_DB' not defined", 1);
    }
    $valid = new Validator;
    if (!$valid->pattern($code, ["code","pattern", "/^218([0-9]{8,11})$/"])) {
      global $code_prefix;
      $code = generate_code($code_prefix["mailing_list"], Data::RAND_NUMBERS, 14, self::$_table_name, self::$_primary_key, self::$_db_name);
    }
    $self = new self(self::$_conn);
    $self->code = $code;
    $self->user = $user;
    $self->title = $title;
    $self->description = $description;
    if ($self->_create()) {
      return $self;
    }
    return false;
  }
  public static function recipients (string $code, string $type = ""):array {
    self::_checkConn();
    if (!self::$_db_name = get_constant("MYSQL_MSG_DB")) {
      throw new \Exception("Messaging Database 'MYSQL_MSG_DB' not defined", 1);
    } if (!(new Validator)->pattern($code, ["code","pattern", "/^218([0-9]{8,11})$/"])) {
      throw new \Exception("Invalid value passed for [code]", 1);
    } if (!(new Validator)->option($type, ["type","option", ["to", "cc", "bcc", "reply-to"]])) {
      $type = false;
    }
    $ret = [];
    $qu = "SELECT * FROM :db:.:tbl: WHERE `mlist` = '{$code}' ";
    if ($type) {
      $qu .= " AND `type`='{$type}'";
    }
    if ($found = (new MultiForm(self::$_db_name, "email_recipients", "id", self::$_conn))->findBySql($qu)) {
      foreach ($found as $rec) {
        $ret[] = Email\Recipient::propagate($rec->address, $rec->name, $rec->surname, $rec->type, $code);
      }
    }
    return $ret;
  }
}