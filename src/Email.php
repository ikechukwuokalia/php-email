<?php
namespace IO;

use TymFrontiers\MySQLDatabase,
    TymFrontiers\Data,
    TymFrontiers\InstanceError,
    TymFrontiers\MultiForm,
    Mailgun\Mailgun,
    TymFrontiers\Validator;

class Email {
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='code';
  protected static $_db_name;
  protected static $_table_name = "emails";
  protected static $_db_fields = [ "code", "folder", "user", "thread", "subject", "body", "template", "origin", "_author", "_created", "_updated"];

  protected $_mgkey;
  protected $_mgdomain;
  protected $_template = null;
  private   $_mail_server = null;
  protected $_replace = [
    "code"            => ["%{code}", ""],
    "subject"         => ["%{subject}", ""],
    "body"            => ["%{body}", ""],
    "message"         => ["%{message}", ""],
    "thread"          => ["%{thread}", ""],
    "name"            => ["%{name}", ""],
    "surname"         => ["%{surname}", ""],
    "email"           => ["%{email}", ""],
    "mlist-title"     => ["%{mlist-title}", ""],
    "mlist-code"      => ["%{mlist-code}", ""],
    "sender-name"     => ["%{sender-name}", ""],
    "sender-surname"  => ["%{sender-surname}", ""],
    "sender-email"    => ["%{sender-email}", ""],
    "unsubscribe"     => ["%{unsubscribe}", ""]
  ];

  const PREFIX = "EML";
  const PREFIX_CODE = "421";
  const CODE_VARIANT = Data::RAND_NUMBERS;

  protected $code;
  protected $folder = "OUTBOX";
  protected $user;
  protected $thread = NULL;
  protected $subject;
  protected $body;
  protected $template;
  protected $origin = NULL;
  private $_body_html;
  private $_body_text;

  protected $_author;
  protected $_created;
  protected $_updated;
  
  private $_isnew = true;
  public $errors = [];
  
  function __construct ($mgdomain = "", $mgkey = "", $conn = false) {
    global $session;
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
    if (!$conn || !$conn instanceof MySQLDatabase || $db_server !== $conn->getServer()) {
      // database user
      if (!$db_user = get_dbuser($srv, $session->access_group())) {
        throw new \Exception("Email database-user not set for [{$session->access_group()}]", 1);
      }
      // set database connection
      $conn = new MySQLDatabase($db_server, $db_user[0], $db_user[1], $db_name);
    }
    
    self::_setConn($conn);
    
    // Email server/request url
    $this->_mail_server = get_server_url($srv);
    // process Mailgun credentials
    $data = new Data;
    $valid = new Validator;
    if ((new Validator)->pattern($mgdomain, ["domain","pattern", "/^([a-z0-9\-\_\.]+)\.([a-z]{2,24})$/"])) {
      $this->_mgdomain = $mgdomain;
    } else {
      // get domain from settings
      $domain = false;
      if (!$domain = setting_get_value("SYSTEM", "API.MAILGUN-DOMAIN", get_constant("PRJ_BASE_DOMAIN"), self::$_conn)) {
        throw new \Exception("API [MG] domain setting failed", 1);
      }
      $this->_mgdomain = $domain;
    } 
    if ((new Validator)->pattern($mgkey, ["key","pattern", "/^key\-([a-z0-9]+)$/"])) {
      $this->_mgkey = $mgkey;
    } else {
      // get api key from settings
      $apikey = false;
      if ($apikey = setting_get_value("SYSTEM", "API.MAILGUN-KEY", get_constant("PRJ_BASE_DOMAIN"), self::$_conn)) {
        $apikey = $data->decodeDecrypt($apikey);
      } if (!$apikey) throw new \Exception("API [MG] key setting failed", 1);
      $this->_mgkey = $apikey;
    }
    global $email_replace_pattern;
    if (!empty($email_replace_pattern) && \is_array($email_replace_pattern)) {
      foreach ($email_replace_pattern as $name => $arr) {
        $this->_replace[$name] = $arr;
      }
    }
    $this->_body_text = "Hello %{name}, if you cannot read HTML version of this email; you can read it in plain text by visiting {$this->_mail_server}/message/read/%{code}";
    // load up template: DEFAULT.EMAIL-TEMPLATE
    if (empty($this->code) && $tmp = setting_get_value("SYSTEM", "DEFAULT.EMAIL-TEMPLATE", get_constant("PRJ_BASE_DOMAIN"), self::$_conn)) {
      
      $tmp = (new Email\Template($tmp, self::$_conn));
      if (!empty($tmp->code)) {
        $this->_template = $tmp;
        $this->template = $tmp->code;
      }
    }
  }

  final public function prep (string $user, string $subject, string $body, string $thread = ""):bool {
    $valid = new Validator;
    if (!$this->user = $valid->pattern($user, ["user","pattern", "/^(SYSTEM|(289|002|252|052|352)([0-9]{8,15}))$/"])) {
      if ($errs = (new InstanceError($valid))->get("pattern", true)) {
        unset($valid->errors["pattern"]);
        foreach ($errs as $er) {
          $this->errors["prep"][] = [1, 256, $er, __FILE__, __DIR__];
        }
      }
    } else if (!$this->subject = $valid->text($subject, ["subject", "text", 3, 128])) {
      if ($errs = (new InstanceError($valid))->get("text", true)) {
        unset($valid->errors["text"]);
        foreach ($errs as $er) {
          $this->errors["prep"][] = [1, 256, $er, __FILE__, __DIR__];
        }
      }
    } else if (!$this->body = $valid->script($body, ["body", "script", 5, 0])) {
      if ($errs = (new InstanceError($valid))->get("script", true)) {
        unset($valid->errors["script"]);
        foreach ($errs as $er) {
          $this->errors["prep"][] = [1, 256, $er, __FILE__, __LINE__];
        }
      }
    } else {
      if (!$this->thread = $valid->pattern($thread, ["thread","pattern", "/^497([0-9]{8,11})$/"])) $this->thread = $this->gen_thread();
      $this->code();
      return true;
    }
    return false;
  }
  final public function save (string $new_folder = "DRAFT"):bool {
    if (!empty($this->code) && !empty($this->body) && !empty($this->subject)) {
      if ($this->_isnew) {
        return $this->_create();
      } else {
        if (\in_array($this->folder, ["DRAFT", "OUTBOX"])) {
          $this->folder = $new_folder;
          return $this->_update();
        }
      }
    }
    return false;
  }
  final public function update ():bool { return false; }
  final public function create ():bool { return false; }
  final public function delete ():bool { return false; }
  // add custom replace patterns
  // always call this function before calling save() | send() | queue()
  final public function replacePattern (string $key, string $pattern, string $value):bool {
    $valid = new Validator;
    if (!$valid->pattern($key, ["key", "pattern", "/^([a-z0-9\_\-]+)$/"])) {
      throw new \Exception("Passed [key] should only contain lower-case letters, numbers, underscores and hyphens", 1);
    } if (!$valid->pattern($pattern, ["pattern", "pattern", "/^\%\{([a-z0-9\-]+)\}$/"])) {
      throw new \Exception("Passed [pattern] should look like %{replace-var}", 1);
    }
    $this->_replace[$key] = [$pattern, $value];
    return true;
  }
  final public function setTemplate (Email\Template $template):bool {
    if (empty($template->body())) return false;
    $this->_template = $template;
    return true;
  }
  public function setOrigin (string $origin):bool {
    if ($origin = (new Validator)->username($origin, ["origin", "username", 2, 56, [], "UPPER", ["-", ".", "_"]])) {
      $this->origin = $origin;
      return true;
    }
    return false;
  }
  public function clearTemplate ():bool {
    $this->_template = null;
    $this->template = "";
    $this->_body_html = $this->body;
    return true;
  }
  public function gen_thread ():string {
    global $code_prefix;
    if (!\is_array($code_prefix) || empty($code_prefix["email_thread"])) {
      throw new \Exception('[$code_prefixed] not set', 1);
    }
    $thread = generate_code($code_prefix["email_thread"], Data::RAND_NUMBERS, 11, $this, "thread", true);
    return $thread;
  }
  public function send (Mailer\Profile $sender, Email\Recipient $recipient, bool | int $log = true,):bool {
    if ($this->folder == "SENT") return true;
    if (empty($this->_mgdomain) || empty($this->_mgkey)) throw new \Exception("Mailgun API domain/key not set", 1);
    if ($recipient->type !== "to") throw new \Exception("Recipent must be the direct receiver type=[To].", 1);
    $body = $this->body;
    if (!$log) $this->body = "**Actual content hidden**";
    $queue = false;
    if (\gettype($log) == "integer" && $log > 0) {
      $this->_isnew = false;
      if(!$queue = (new MultiForm (self::$_db_name, "email_log", "id", self::$_conn))->findById($log)) {
        $this->errors["send"][] = [0, 256, "No record found for queue [id]:  {$log}", __FILE__, __LINE__];
      }
    } 
    if ($this->_isnew && !$this->save()) return false;
    // prepare to send
    $body_html = (new \Parsedown())->text(\html_entity_decode($body));
    $this->_body_html = !empty($this->_template) ? $this->_template->body() : "";
    $this->_replace["code"][1] = $this->code;
    $this->_replace["body"][1] = $body_html ? $body_html : $body;
    $this->_replace["message"][1] = $body_html ? $body_html : $body;
    $this->_replace["name"][1] = $recipient->name;
    $this->_replace["surname"][1] = $recipient->surname;
    $this->_replace["email"][1] = $recipient->address;
    $mlist = !empty($recipient->mlist) ? (new MailingList(self::$_conn))->findById($recipient->mlist) : false;
    if ($mlist && !empty($mlist->code)) {
      $this->_replace["mlist-title"][1] = $mlist->title;
      $this->_replace["mlist-code"][1] = $mlist->code;
      // generate unsbscribe list
      $unsubscribe = "<p>This email came from the <b>Mailing List:</b> {$mlist->title}. <a href=\"{$this->_mail_server}/mailing-list/{$mlist->code}?task=unsubscribe\">Unsubscribe me</a> from this list.</p>";
      $this->_replace["unsubscribe"][1] = $unsubscribe;
    } 
    $this->_replace["sender-name"][1] = $sender->name;
    $this->_replace["sender-surname"][1] = $sender->surname;
    $this->_replace["sender-email"][1] = $sender->address;
    // replace and send
    $subject = $this->subject;
    foreach ($this->_replace as $name => $replace) {
      $subject = \str_replace($replace[0], (!empty($replace[1]) ? $replace[1] : ""), $subject);
    }
    $this->_replace["subject"][1] = $this->subject;
    foreach ($this->_replace as $name => $replace) {
      $this->_body_html = \str_replace($replace[0], (!empty($replace[1]) ? $replace[1] : ""), $this->_body_html);
      $this->_body_text = \str_replace($replace[0], (!empty($replace[1]) ? $replace[1] : ""), $this->_body_text);
    }
    // init mailgun
    $mgClient = Mailgun::create($this->_mgkey);
    try {
      $result = $mgClient->messages()->send($this->_mgdomain, [
        'from' => $sender->getAddress(),
        'to' => $recipient->getAddress(),
        'subject' => $subject,
        'text' => $this->_body_text,
        'html' => $this->_body_html
      ]);
      if( \is_object($result) && !empty($result->getId()) && \strpos($result->getId(), $this->_mgdomain) !== false ){
        // save queue
        if ($log !== false) {
          $do_log = false;
          if ($queue instanceof MultiForm && !empty($queue->id)) {
            $queue->qref = $result->getId();
            $queue->sent = true;
            $do_log = $queue->update();
          } else {
            $queue = new MultiForm (self::$_db_name, "email_log", "id", self::$_conn);
            $queue->priority = 0;
            $queue->qref = $result->getId();
            $queue->sent = 1;
            $queue->email = $this->code;
            $queue->sender = $sender->id;
            $queue->recipient = $recipient->id;
            $do_log = $queue->create();
          }
          if (!$do_log) {
            $this->errors['send'][] = [0, 256, "Failed to save/update log after sending message.", __FILE__, __LINE__];
            $queue->mergeErrors();
            if ($log_errs = (new InstanceError($queue, true))->get("", true)) {
              foreach ($log_errs as $key => $lerrs) {
                foreach ($lerrs as $err) {
                  $this->errors['send'][] = [0, 256, "[{$key}]: {$err}", __FILE__, __LINE__];
                }
              }
            }
            return false;
          } else {
            $this->_isnew = false;
            $this->save("SENT");
            return true;
          }
        } else {
          $this->_isnew = false;
          $this->save("SENT");
          return true;
        }
      }
    } catch (\Exception $e) {
      $this->errors['send'][] = [0, 256, "[Mailgun-Error]:  {$e->getMessage()}", __FILE__, __LINE__];
      return false;
    }
    return false;
  }
  final public function queue (int $priority, Mailer\Profile $sender, Email\Recipient $recipient):bool {
    $this->folder = "OUTBOX";
    if ($this->save()) {
      $queue = new MultiForm (self::$_db_name, "email_log", "id", self::$_conn);
      $queue->priority = $priority;
      $queue->sent = 0;
      $queue->email = $this->code;
      $queue->sender = $sender->id;
      $queue->recipient = $recipient->id;
      if (!$queue->create()) {
        $this->errors['queue'][] = [0, 256, "Failed to save log after sending message.", __FILE__, __LINE__];
        $queue->mergeErrors();
        if ($log_errs = (new InstanceError($queue, true))->get("", true)) {
          foreach ($log_errs as $key => $lerrs) {
            foreach ($lerrs as $err) {
              $this->errors['queue'][] = [0, 256, "[{$key}]: {$err}", __FILE__, __LINE__];
            }
          }
        }
        return false;
      }
      return true;
    }
    return false;
  }
  final public function saveDraft ():bool {
    $this->folder = "DRAFT";
    return $this->_isnew ? $this->_create() : $this->_update();
  }
  final public function code ():string {
    if (empty($this->code)) {
      global $code_prefix;
      if (!\is_array($code_prefix) || empty($code_prefix["email"])) {
        throw new \Exception('[$code_prefixed] not set', 1);
      }
      $code = $this->code = generate_code(self::PREFIX_CODE, self::CODE_VARIANT, 11, $this, "code", true);
      return $code;
    }
    return $this->code;
  }
  public function folder ():string { return $this->folder; }
  public function subject ():string { return $this->subject; }
  public function thread ():string { return $this->thread; }
  public function body ():string { return $this->body; }
  public function template () { return $this->template; }
  public function conn () { return self::$_conn; }
  final protected function _doReplace ():bool {
    if (!empty($this->body)) {
      foreach ($this->_replace as $name => $replace) {
        $this->body = \str_replace($replace[0], $replace[1], $this->body);
      }
      return true;
    }
    return false;
  }
}