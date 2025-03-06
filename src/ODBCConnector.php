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
const SNOWFLAKE_ATTR_PRIV_KEY_FILE = 'private_key_file';
const SNOWFLAKE_ATTR_PRIV_KEY_FILE_PWD = 'private_key_file_pwd';
const SNOWFLAKE_ATTR_AUTHENTICATOR = 'authenticator';

class ODBCConnector extends Connector implements ConnectorInterface, OdbcDriver
{
    /**
     * Set dynamically the DSN prefix in case we need it.
     */
    protected string $dsnPrefix = 'odbc';

    /**
     * Include the "Driver=" property in the DSN.
     * In case of Snowflake connection via snowflake_pdo driver we dont want it.
     */
    protected bool $dsnIncludeDriver = true;

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
        
        // Check for key pair authentication for Snowflake
        if ($this->dsnPrefix === 'snowflake' && 
            isset($config['private_key_path']) && 
            file_exists($config['private_key_path'])) {
            
            // Add key pair auth parameters to the options
            $options[SNOWFLAKE_ATTR_PRIV_KEY_FILE] = $config['private_key_path'];
            
            if (isset($config['private_key_passphrase'])) {
                $options[SNOWFLAKE_ATTR_PRIV_KEY_FILE_PWD] = $config['private_key_passphrase'];
            }
            
            // Add authenticator parameter for key pair auth
            $options[SNOWFLAKE_ATTR_AUTHENTICATOR] = 'snowflake';
        }

        // FULL DSN ONLY
        if ($dsn = Arr::get($config, 'dsn')) {
            $dsn = ! Str::contains($this->dsnPrefix.':', $dsn) ? $this->dsnPrefix.':'.$dsn : $dsn;
        }
        // dynamicly build in some way..
        else {
            $dsn = $this->buildDsnDynamicly($config);
        }

        return $this->createConnection($dsn, $config, $options);
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
