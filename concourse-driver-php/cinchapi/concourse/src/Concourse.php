<?php
require_once dirname(__FILE__) . "/autoload.php";

use Cinchapi\Concourse\Core as core;
use Thrift\Transport\TSocket;
use Thrift\Transport\TBufferedTransport;
use Thrift\Protocol\TBinaryProtocolAccelerated;
use Thrift\ConcourseServiceClient;
use Thrift\Data\TObject;
use Thrift\Shared\TransactionToken;
use Thrift\Shared\Type;
use Thrift\Shared\AccessToken;

/**
* Concourse is a self-tuning database that makes it easier for developers to
* quickly build robust and scalable systems. Concourse dynamically adapts on a
* per-application basis and offers features like automatic indexing, version
* control, and distributed ACID transactions within a big data platform that
* reduces operational complexity. Concourse abstracts away the management and
* tuning aspects of the database and allows developers to focus on what really
* matters.
*/
class Concourse {

    /**
    * Create a new client connection.
    *
    * @param string $host the server host (optional, defaults to 'localhost')
    * @param integer $port the listener port (optional, defaults to 1717)
    * @param string $username the username with which to connect (optional, defaults to 'admin')
    * @param string $password the password for the username (optional, defaults to 'admin')
    * @param string $environment the environment to use (optional, defaults to the 'value of the default_environment' variable in the server's concourse.prefs file
    * @return Concourse
    * @throws Exception if the connection cannot be established
    */
    public static function connect($host = "localhost", $port = 1717,
    $username = "admin", $password = "admin", $environment = "") {
        return new Concourse($host, $port, $username, $password, $environment);
    }

    /**
     * @var string the server host where the client is connected.
     */
    private $host;

    /**
     * @var integer the server port where the client is connected.
     */
    private $port;

    /**
     * @var string the username on behalf of whom the client is connected.
     */
    private $username;

    /**
     * @var string the password for the $username.
     */
    private $password;

    /**
     * @var string the server environment where the client is connected.
     */
    private $environment;

    /**
     * @var ConcourseServerClient the thrift client
     */
    private $client;

    /**
     * @var Thrift\Shared\TransactionToken the token that identifies the client's server-side transaction. This value is NULL if the client is in autocommit mode.
     */
    private $transaction;

    /**
     * @var Thrift\Shared\AccessToken the access token that is used to identify, authenticate and authorize the client after the initial connection.
     */
    private $creds;

    /**
     * @internal
     * Use Concourse::connect instead of directly calling this constructor.
     */
    private function __construct($host="localhost", $port=1717, $username="admin", $password="admin", $environment="") {
        $kwargs = func_get_arg(0);
        if(core\is_assoc_array($kwargs)){
            $host = "localhost";
            $prefs = core\find_in_kwargs_by_alias("prefs", $kwargs);
            if(!empty($prefs)){
                $prefs = parse_ini_file(core\expand_path($prefs));
            }
            else{
                $prefs = [];
            }
        }
        else{
            $kwargs = [];
            $prefs = [];
        }
        // order of precedence for args: prefs -> kwargs -> positional -> default
        $this->host = $prefs["host"] ?: $kwargs["host"] ?: $host;
        $this->port = $prefs["port"] ?: $kwargs["port"] ?: $port;
        $this->username = $prefs["username"] ?: core\find_in_kwargs_by_alias('username', $kwargs) ?: $username;
        $this->password = $prefs["password"] ?: core\find_in_kwargs_by_alias("password", $kwargs) ?: $password;
        $this->environment = $prefs["environment"] ?: $kwargs["environment"] ?: $environment;
        try {
            $socket = new TSocket($this->host, $this->port);
            $transport = new TBufferedTransport($socket);
            $protocol = new TBinaryProtocolAccelerated($transport);
            $this->client = new ConcourseServiceClient($protocol);
            $transport->open();
            $this->authenticate();
        }
        catch (TException $e) {
            throw new Exception("Could not connect to the Concourse Server at "
            . $this->host . ":" . $this->port);
        }
    }

    /**
     * Abort the current transaction and discard any changes that were staged.
     * After returning, the driver will return to autocommit mode and all
     * subsequent changes will be committed immediately.
    */
    public function abort() {
        if(!empty($this->transaction)){
            $token = $this->transaction;
            $this->transaction = null;
            $this->client->abort($this->creds, $token, $this->environment);
        }
    }

    /**
     * Add a value if it does not already exist.
     *
     * @api
     ** <strong>add($key, $value, $record)</strong> - Add a value to a field in a single record and return a flag that indicates whether the value was added to the field
     ** <strong>add($key, $value, $records)</strong> - Add a value to a field in multiple records and return a mapping from each record to a boolean flag that indicates whether the value was added to the field
     ** <strong>add($key, $value)</strong> - Add a value to a field in a new record and return the id of the new record
     *
     * @param string $key the field name
     * @param mixed $value the value to add
     * @param integer $record The record where the data should be added (optional)
     * @param array $records The records where the data should be added (optional)
     * @return boolean|array|integer
     * @throws Thrift\Exceptions\InvalidArgumentException
     */
    public function add() {
        return $this->dispatch(func_get_args());
    }

    /**
     * Describe changes made to a record or a field over time.
     *
     * @api
     ** <strong>audit($key, $record)</strong> - Describe all the changes made to a field over time.
     ** <strong>audit($key, $record, $start)</strong> - Describe all the changes made to a field since the specified <em>start</em> timestamp.
     ** <strong>audit($key, $record, $start, $end)</strong> - Describe all the changes made to a field between the specified <em>start</em> and <em>end</em> timestamps.
     ** <strong>audit($record)</strong> - Describe all the changes made to a record over time.
     ** <strong>audit($record, $start)</strong> - Describe all the changes made to a record since the specified <em>start</em> timestamp.
     ** <strong>audit($record, $start, $end)</strong> - Describe all the changes made to a record between the specified <em>start</em> and <em>end</em> timestamps.
     *
     * @param string $key the field name (optional)
     * @param integer $record the record that contains the $key field or the record to audit if no $key is provided
     * @param integer|string $start the earliest timestamp to check (optional)
     * @param integer|string $end the latest timestamp to check (optional)
     * @return array mapping from each timestamp to a description of the change that occurred.
     */
    public function audit(){
        return $this->dispatch(func_get_args());
    }

    /**
    *
    * @param type $keys
    * @param type $timestamp
    * @return type
    */
    public function browse($keys=null, $timestamp=null){
        $array = func_get_arg(0);
        if(is_array($array)){
            $keys = $array['keys'];
            $keys = empty($keys) ? $array['key'] : $keys;
            $timestamp = $array['timestamp'];
        }
        $timestamp = is_string($timestamp) ? Convert::stringToTime($timestamp):
        $timestamp;
        if(is_array($keys) && !empty($timestamp)){
            $data = $this->client->browseKeysTime($keys, $timestamp, $this->creds,
            $this->transaction, $this->environment);

        }
        else if(is_array($keys)){
            $data = $this->client->browseKeys($keys, $this->creds,
            $this->transaction, $this->environment);
        }
        else if(!empty($timestamp)){
            $data = $this->client->browseKeyTime($keys, $timestamp, $this->creds,
            $this->transaction, $this->environment);
        }
        else{
            $data = $this->client->browseKey($keys, $this->creds,
            $this->transaction, $this->environment);
        }
        return Convert::phpify($data);
    }

    public function get($keys=null, $criteria=null, $records=null, $timestamp=null){
        $kwargs = func_get_arg(0);
        if(is_array($kwargs)){
            $keys = null;
        }
        else{
            $kwargs = null;
        }
        $keys = $keys ?: $kwargs['key'] ?: $kwargs['keys'];
        $criteria = $criteria ?: core\find_in_kwargs_by_alias('criteria', $kwargs);
        $records = $records ?: $kwargs['record'] ?: $kwargs['records'];
        $timestamp = $timestamp ?: core\find_in_kwargs_by_alias('timestamp', $kwargs);
        $data = $this->client->getKeyRecord($keys, $records, $this->creds,
        $this->transaction, $this->environment);
        return Convert::thriftToPhp($data);
    }

    public function logout(){
        $this->client->logout($this->creds, $this->environment);
    }

    public function set(){
        $this->dispatch(func_get_args());
    }

    /**
    * Start a new transaction.
    */
    public function stage(){
        $this->transaction = $this->client->stage($this->creds, $this->environment);
    }

    public function time(){
        return $this->dispatch(func_get_args());
    }

    /**
    * Login with the username and password and locally store the AccessToken
    * to use with subsequent CRUD methods.
    *
    * @throws Thrift\Exceptions\SecurityException
    */
    private function authenticate() {
        try {
            $this->creds = $this->client->login($this->username, $this->password,
            $this->environment);
        }
        catch (TException $e) {
            throw e;
        }
    }

    /**
     * When called from a method within this class, dispatch to the appropriate
     * thrift callable based on the arguments that are passed in.
     *
     * Usage:
     * return $this->dispatch(func_get_args());
     * @return mixed
     */
    private function dispatch(){
        $args = func_get_args()[0];
        $end = count($args) - 1;
        if(core\is_assoc_array($args[$end])){
            $kwargs = $args[$end];
            unset($args[$end]);
        }
        else{
            $kwargs = array();
        }
        $method = debug_backtrace()[1]['function'];
        $tocall = Dispatcher::send($method, $args, $kwargs);
        $callback = array($this->client, array_keys($tocall)[0]);
        $params = array_values($tocall)[0];
        $params[] = $this->creds;
        $params[] = $this->transaction;
        $params[] = $this->environment;
        return call_user_func_array($callback, $params);
    }

}
