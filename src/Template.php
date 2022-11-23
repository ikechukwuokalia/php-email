<?php
namespace IO\Email;

use TymFrontiers\InstanceError;
use TymFrontiers\MultiForm;
use \TymFrontiers\MySQLDatabase,
    \TymFrontiers\Data,
    \TymFrontiers\Validator;
use function \Catali\get_constant;
use function \Catali\generate_code;
use function \Catali\get_dbserver;
use function \Catali\get_database;
use function \Catali\get_dbuser;

class Template {
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='code';
  protected static $_db_name;
  protected static $_table_name = "email_templates";
  protected static $_db_fields = [
    "code",
    "user",
    "title", 
    "body",
    "is_md",
    "_author",
    "_created"
  ];

  const PREFIX = "EMT";
  const PREFIX_CODE = "429";
  const CODE_VARIANT = Data::RAND_NUMBERS;

  public $code;
  public $user;
  public $title;
  public $body;
  public $is_md = false;
  protected $_author;
  protected $_created;

  public $errors = [];

  function __construct ($code = "", $conn = false) {
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
    // check if/to initialize
    if ($code && (new Validator)->pattern($code, ["code","pattern", "/^429([0-9]{8,11})$/"])) {
      if ($found = (new MultiForm(self::$_db_name, self::$_table_name, self::$_primary_key, self::$_conn))->findById($code)) {
        foreach ($found as $prop=>$value) {
          $this->$prop = $value;
        }
      }
    }
  }
  
  public function create (string $title, string $body, string $user, bool $is_md = false):bool {
    $valid = new Validator;
    if (!$this->title = $valid->text($title, ["title", "text", 5, 128])) {
      $this->errors["create"][] = [ 1, 256, "Invalid value for [title].", __FILE__, __LINE__];
      if ($errs = (new InstanceError($valid, true))->get("text", true)) {
        foreach ($errs as $er) {
          $this->errors["create"][] = [1, 256, $er, __FILE__, __DIR__];
        }
      }
    } else if (!$this->body = $valid->script($body, ["body", "script", 56, 0])) {
      $this->errors["create"][] = [ 1, 256, "Invalid value for [body].", __FILE__, __LINE__ ];
      if ($errs = (new InstanceError($valid, true))->get("script", true)) {
        foreach ($errs as $er) {
          $this->errors["create"][] = [1, 256, $er, __FILE__, __DIR__];
        }
      }
    } else if (!$this->user = $valid->pattern($user, ["user","pattern", "/^(289|002|252|052|352)([0-9]{8,11})$/"])) {
      $this->errors["create"][] = [ 1, 256, "Invalid value for [user].", __FILE__, __LINE__ ];
      if ($errs = (new InstanceError($valid, true))->get("pattern", true)) {
        foreach ($errs as $er) {
          $this->errors["create"][] = [1, 256, $er, __FILE__, __DIR__];
        }
      }
    } else {
      try {
        $this->_gen_code();
      } catch (\Throwable $th) {
        $this->errors["create"][] = [1, 256, $th->getMessage(), __FILE__, __DIR__];
      }
      if (empty($this->errors)) {
        $this->is_md = $is_md;
        return $this->_create();
      }
    }
    return false;
  }
  public function replace (string $patten, string $value):void {
    if (!empty($this->body)) {
      $this->body = \str_replace($patten, $value, $this->body);
    }
  }
  public function body ():string {
    if (empty($this->body)) return "";
    if ($this->is_md) {
      return \html_entity_decode((new \Parsedown())->text($this->body));
    }
    return \html_entity_decode($this->body);
  }
  protected function _gen_code ():bool {
    global $code_prefix;
    if (!\is_array($code_prefix) || empty($code_prefix["email_template"])) {
      throw new \Exception('[$code_prefixed] not set', 1);
    }
    if ($this->code = generate_code(self::PREFIX_CODE, self::CODE_VARIANT, 11, $this, "code", true)) {
      return true;
    }
    return false;
  }
}