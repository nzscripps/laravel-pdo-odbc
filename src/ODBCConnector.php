<?php

namespace LaravelPdoOdbc;

use Closure;
use Exception;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelPdoOdbc\Contracts\OdbcDriver;
use PDO;

/**
 * Snowflake PDO constants
 */
const SNOWFLAKE_ATTR_PRIV_KEY_FILE = 'priv_key_file';
const SNOWFLAKE_ATTR_PRIV_KEY_FILE_PWD = 'priv_key_file_pwd';
const SNOWFLAKE_ATTR_AUTHENTICATOR = 'authenticator';

class ODBCConnector extends Connector implements ConnectorInterface, OdbcDriver
{
    /**
     * Set dynamically the DSN prefix in case we need it.
     *
     * @var string
     */
    protected $dsnPrefix = 'odbc';

    /**
     * When set true, you're able to include a driver parameter in the DSN string, handy for Snowflake.
     *
     * @var bool
     */
    protected $dsnIncludeDriver = false;
    
    /**
     * Flag to enable debug logging
     *
     * @var bool
     */
    protected $shouldLog = false;
    
    /**
     * Path to the log file
     *
     * @var string
     */
    protected $logFilePath = '/tmp/snowflake_connection.log';

    /**
     * Log debug information to a file
     * 
     * @param string $message 
     * @param array $context 
     */
    protected function logDebug(string $message, array $context = [])
    {
        if (!$this->shouldLog) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = json_encode($context, JSON_PRETTY_PRINT);
        $logMessage = "[$timestamp] $message" . ($contextJson ? "\nContext: $contextJson" : '') . "\n\n";
        
        file_put_contents($this->logFilePath, $logMessage, FILE_APPEND);
    }

    /**
     * Establish a database connection.
     *
     * @return PDO
     *
     * @internal param array $options
     */
    public function connect(array $config)
    {
        $options = $this->getOptions($config);
        
        // Set debug flag
        $this->shouldLog = $config['debug_connection'] ?? $config['debug'] ?? false;
        
        // Set custom log path if provided
        if (isset($config['log_path'])) {
            $this->logFilePath = $config['log_path'];
        }
        
        $this->logDebug('ODBC Connection attempt started', [
            'driver' => $config['driver'] ?? 'unknown',
            'dsnPrefix' => $this->dsnPrefix,
            'hasKeyPair' => isset($config['private_key_path']) && file_exists($config['private_key_path']),
            'keyPairPath' => $config['private_key_path'] ?? 'not set',
            'keyPairExists' => isset($config['private_key_path']) ? file_exists($config['private_key_path']) : false,
            'hasKeyPassphrase' => isset($config['private_key_passphrase']),
            'username' => $config['username'] ?? 'not set',
        ]);
        
        // Check for key pair authentication for Snowflake
        if ($this->dsnPrefix === 'snowflake' && 
            isset($config['private_key_path']) && 
            file_exists($config['private_key_path'])) {
            
            $this->logDebug('Configuring Snowflake key pair authentication', [
                'privateKeyPath' => $config['private_key_path'],
                'hasPassphrase' => isset($config['private_key_passphrase']),
                'fileSize' => filesize($config['private_key_path']),
                'filePermissions' => substr(sprintf('%o', fileperms($config['private_key_path'])), -4),
            ]);

            // Setting empty password for key pair auth
            $config['password'] = '';
            
            // Set authenticator parameter to match Snowflake docs exactly
            // Note: Move this to the start of additional parameters for better compatibility
            $config['authenticator'] = 'SNOWFLAKE_JWT';
            
            // Add key pair auth parameters as DSN parameters
            $config['priv_key_file'] = $config['private_key_path'];
            
            if (isset($config['private_key_passphrase'])) {
                // Use the passphrase via PDO options instead of DSN to avoid escaping issues
                $options[PDO::ATTR_STRINGIFY_FETCHES] = false;
                $options['priv_key_file_pwd'] = $config['private_key_passphrase'];
                
                // Remove from config to keep it out of the DSN string
                unset($config['priv_key_file_pwd']);
            }
            
            $this->logDebug('Snowflake key pair parameters added to config', [
                'privKeyFile' => $config['priv_key_file'],
                'hasPassphraseOption' => isset($options['priv_key_file_pwd']),
                'authenticator' => $config['authenticator'],
                'allConfigKeys' => array_keys($config),
            ]);
            
            // Read a bit of the private key file to check format
            $keyFileContents = file_get_contents($config['private_key_path'], false, null, 0, 100);
            $this->logDebug('Private key file prefix', [
                'filePrefix' => substr($keyFileContents, 0, 30),
                'isPEM' => strpos($keyFileContents, '-----BEGIN PRIVATE KEY-----') !== false,
                'isRSA' => strpos($keyFileContents, '-----BEGIN RSA PRIVATE KEY-----') !== false,
            ]);
        } elseif ($this->dsnPrefix === 'snowflake') {
            $this->logDebug('Snowflake key pair authentication not configured correctly', [
                'hasPrivateKeyPath' => isset($config['private_key_path']),
                'privateKeyPath' => $config['private_key_path'] ?? 'not set',
                'privateKeyExists' => isset($config['private_key_path']) ? file_exists($config['private_key_path']) : false,
            ]);
        }

        // FULL DSN ONLY
        if ($dsn = Arr::get($config, 'dsn')) {
            $dsn = ! Str::contains($this->dsnPrefix.':', $dsn) ? $this->dsnPrefix.':'.$dsn : $dsn;
        }
        // dynamicly build in some way..
        else {
            $dsn = $this->buildDsnDynamicly($config);
            
            $this->logDebug('Built DSN string dynamically', [
                'dsn' => $dsn
            ]);
        }
        
        try {
            $connection = $this->createConnection($dsn, $config, $options);
            
            $this->logDebug('ODBC Connection successful');
            
            return $connection;
        } catch (\PDOException $e) {
            $this->logDebug('ODBC Connection failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'dsn' => $dsn,
                'options' => json_encode($options),
            ]);
            throw $e;
        }
    }

    /**
     * Register the connection driver into the DatabaseManager.
     */
    public static function registerDriver(): Closure
    {
        return function ($connection, $database, $prefix, $config) {
            $connection = (new self())->connect($config);
            if ($flavour = Arr::get($config, 'options.flavour')) {
                $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [$flavour, [$connection]]);
            }
            $connection = new ODBCConnection($connection, $database, $prefix, $config);

            return $connection;
        };
    }

    /**
     * When dynamically building it takes the database configuration key and put it in the DSN.
     */
    protected function buildDsnDynamicly(array $config): string
    {
        // ignore some default props...
        $ignoreProps = $this->dsnPrefix === 'snowflake' ?
            ['driver', 'odbc_driver', 'dsn', 'options', 'port', 'server', 'username', 'password', 'name', 'prefix', 'private_key_path', 'private_key_passphrase'] :
            ['driver', 'odbc_driver', 'dsn', 'options', 'username', 'password', 'name', 'prefix'];
        $props = Arr::except($config, $ignoreProps);

        if ($this->dsnIncludeDriver) {
            $props = ['driver' => Arr::get($config, 'odbc_driver')] + $props;

            // throw exception in case dynamically buildup is missing the odbc driver absolute path.
            if (! Arr::get($config, 'odbc_driver')) {
                throw new Exception('Please make sure the environment variable: "DB_ODBC_DRIVER" was set properly in the .env file. DB_ODBC_DRIVER should be the absolute path to the database driver file.');
            }
        }

        // join pieces DSN together
        $props = array_map(function ($val, $key) {
            return $key.'='.$val;
        }, $props, array_keys($props));

        return $this->dsnPrefix.':'.implode(';', $props);
    }
}
