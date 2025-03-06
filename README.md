# ODBC/Snowflake Integration for Laravel Framework

This repository provides seamless integration of ODBC/Snowflake with Laravel Eloquent.
It aims to create a comprehensive ODBC package for Laravel, while also
functioning as a standalone solution.

Unlike the `odbc_*` functions, this package utilizes the `PDO` class,
resulting in smoother and more convenient integration with Eloquent.

The primary goal of this package is to offer a standardized approach to connect
with an ODBC connection. It supports custom grammars and schemas to accommodate
various ODBC connections, such as Snowflake.

## How to Install

Before proceeding, ensure that you have PHP version 8.x installed on your system.

To add the package to your project, run the following command:

```bash
composer require yoramdelangen/laravel-pdo-odbc
```

By default, the package will be automatically registered through the
`package:discover` command.

Alternatively, you can manually register the service provider in the `app.php` file:

```php
'providers' => [
  // ...
  LaravelPdoOdbc\ODBCServiceProvider::class,
];
```

If you intend to use the `snowflake_pdo` PHP extension, please follow the
installation guide provided [here](https://github.com/snowflakedb/pdo_snowflake/)
to set it up.

Starting from version `1.2.0`, the package includes support for `snowflake_pdo`,
but it will still function without the Snowflake extension (via ODBC).

## Configuration

The available driver flavors are:

- ODBC (generic)
- Snowflake (via ODBC and native through PHP extension)
- ...

### Snowflake Specific environment variables

You have the option to customize the Snowflake driver using the following parameters:

```ini
# When set to `false`, column names are automatically uppercased.
SNOWFLAKE_COLUMNS_CASE_SENSITIVE=false

# When set to `true`, column names are wrapped in double quotes and their
# case is determined by the input.
SNOWFLAKE_COLUMNS_CASE_SENSITIVE=true
```

## Usage

Configuring the package is straightforward:

**Add a Database Configuration to `database.php`**

Starting from version 1.2, we recommend using the native Snowflake extension
instead of ODBC, but we'll keep supporting it.

```php
'snowflake_pdo' => [
    'driver' => 'snowflake_native',
    'account' => '{account_name}.eu-west-1',
    'username' => '{username}',
    'password' => '{password}',
    'database' => '{database}',
    'warehouse' => '{warehouse}',
    'schema' => 'PUBLIC', // change it if necessary.
    'options' => [
        // Required for Snowflake usage
        \PDO::ODBC_ATTR_USE_CURSOR_LIBRARY => \PDO::ODBC_SQL_USE_DRIVER
    ]
],

// Using key pair authentication with Snowflake
'snowflake_keypair' => [
    'driver' => 'snowflake_native',
    'account' => '{account_name}.eu-west-1',
    'username' => env('SNOWFLAKE_USER'),
    'private_key_path' => resource_path('private_key.pem'),
    'private_key_passphrase' => env('SNOWFLAKE_PRIVATE_KEY_PASSPHRASE', null),
    'database' => '{database}',
    'warehouse' => '{warehouse}',
    'schema' => 'PUBLIC', // change it if necessary.
    'options' => [
        // Required for Snowflake usage
        \PDO::ODBC_ATTR_USE_CURSOR_LIBRARY => \PDO::ODBC_SQL_USE_DRIVER
    ]
],
```

You have multiple ways to configure the ODBC connection:

1. Simple configuration using DSN only:

   ```php
   'odbc-connection-name' => [
       'driver' => 'odbc',
       'dsn' => 'OdbcConnectionName', // odbc: will be prefixed
       'username' => 'username',
       'password' => 'password'
   ]
   ```

   or, if you don't have a datasource configured within your ODBC Manager:

   ```php
   'odbc-connection-name' => [
       'driver' => 'odbc',
       'dsn' => 'Driver={Your Snowflake Driver};Server=snowflake.example.com;Port=443;Database={DatabaseName}',
       'username' => 'username',
       'password' => 'password'
   ]
   ```

   > Note: The DSN `Driver` parameter can either be an absolute path to your
   > driver file or the name registered within the `odbcinst.ini` file/ODBC manager.

2. Dynamic configuration:

   ```php
   'odbc-connection-name' => [
       'driver' => 'snowflake',
       // please change this path accordingly your exact location
       'odbc_driver' => '/opt/snowflake/snowflakeodbc/lib/universal/libSnowflake.dylib',
       // 'odbc_driver' => 'Snowflake path Driver',
       'server' => 'host.example.com',
       'username' => 'username',
       'password' => 'password',
       'warehouse' => 'warehouse name',
       'schema' => 'PUBLIC', // most ODBC connections use the default value
   ]
   ```

   > All fields, except for `driver`, `odbc_driver`, `options`, `username`, and
   > `password`, will be dynamically added to the DSN connection string.
   >
   > Note: The DSN `odbc_driver` parameter can either be an absolute path to
   > your driver file or the name registered within the `odbcinst.ini`
   > file/ODBC manager.

3. Using key pair authentication with Snowflake:

   ```php
   'snowflake_keypair' => [
       'driver' => 'snowflake',
       'odbc_driver' => '/opt/snowflake/snowflakeodbc/lib/universal/libSnowflake.dylib',
       'server' => 'host.example.com',
       'username' => env('SNOWFLAKE_USER'),
       'private_key_path' => resource_path('private_key.pem'),
       'private_key_passphrase' => env('SNOWFLAKE_PRIVATE_KEY_PASSPHRASE', null),
       'warehouse' => 'warehouse name',
       'schema' => 'PUBLIC',
       // Optional: Explicitly set authenticator according to Snowflake docs
       'authenticator' => 'SNOWFLAKE_JWT',
       // Optional debug configuration
       'debug_connection' => true,
       'log_path' => storage_path('logs/snowflake_connection.log'),
   ]
   ```

   > Instead of using a password, this configuration uses a private key file for authentication.
   > The `private_key_path` should point to a PEM-formatted private key file (p8 file), and 
   > `private_key_passphrase` is optional if your key is protected with a passphrase.
   > According to Snowflake documentation, when using key pair authentication:
   > - The authenticator must be set to `SNOWFLAKE_JWT`
   > - The password is set to an empty string (this is handled automatically by the connector)
   > - The private key file should be accessible to the web server user

### Troubleshooting Key Pair Authentication

If you get authentication errors when using key pair authentication, check these issues:

1. **For "password parameter is missing" or "authenticator initialization failed" errors:**
   - Make sure your configuration exactly matches Snowflake's expected format:
   
   ```php
   'snowflake_keypair' => [
       'driver' => 'snowflake_native',
       'account' => '{account_name}.region', // Account identifier
       'username' => env('SNOWFLAKE_USER'),
       'private_key_path' => '/absolute/path/to/rsa_key.p8', // Must be absolute path
       'private_key_passphrase' => env('SNOWFLAKE_PRIVATE_KEY_PASSPHRASE', null),
       'database' => '{database}',
       'warehouse' => '{warehouse}',
       'schema' => '{schema}',
       // Debug settings
       'debug_connection' => true,
       'log_path' => '/path/to/logs/snowflake.log',
   ]
   ```

2. **Key file format requirements:**
   - Snowflake expects a PKCS#8 format private key (p8 file)
   - The file must start with `-----BEGIN PRIVATE KEY-----` (for unencrypted) or `-----BEGIN ENCRYPTED PRIVATE KEY-----` (for encrypted)
   - If your key is in traditional PEM format beginning with `-----BEGIN RSA PRIVATE KEY-----`, convert it:
     ```bash
     openssl pkcs8 -topk8 -inform PEM -outform PEM -in private_key.pem -out private_key.p8
     ```
     (Omit the `-nocrypt` option if you want to encrypt it with a passphrase)

3. **Connection string format:**
   - The exact DSN format Snowflake requires is:
     ```
     account=<account_name>;authenticator=SNOWFLAKE_JWT;priv_key_file=<path>/rsa_key.p8;priv_key_file_pwd=<passphrase>
     ```
   - Additional parameters like database, warehouse, schema can be appended
   - The username is provided as the second parameter to the PDO constructor
   - The password parameter (third parameter to PDO) must be an empty string `""`

4. **File access and permissions:**
   - Ensure the web server has read access to the private key file
   - Check file permissions (should be 0600 or 0400 for security)
   - Use absolute paths to avoid path resolution issues

5. **Path Escaping Issues:**
   - If your connection fails with path escaping issues (paths show as `/var\/www\/...`), try these solutions:
     - Use directory separators appropriate for your OS (Windows: `\\`, Unix: `/`)
     - Avoid using Laravel helpers like `resource_path()` - use a full absolute path instead
     - Double-check the path in your logs to ensure it doesn't contain escape characters
     - If storing the path in JSON config, ensure it's not being double-escaped

Remember to check the logs for detailed information about the connection attempt.

## Eloquent ORM

You can use Laravel, Eloquent ORM, and other Illuminate components as usual.

```php
# Facade
$books = DB::connection('odbc-connection-name')
            ->table('books')
            ->where('Author', 'Abram Andrea')
            ->get();

# ORM
$books = Book::where('Author', 'Abram Andrea')->get();
```

## Troubleshooting and more info

We have documented all weird behavious we encountered with the ODBC driver for
Snowflake. In case of trouble of weird messages, checkout the following links:

- [Snowflake ODBC](docs/snowflake-odbc.md)
- [Snowflake ODBC Troubleshooting](docs/snowflake-odbc-troubleshooting.md)

## Customization

- [Custom `getLastInsertId()` Function](docs/custom-last-insert-id.md)
- [Custom Processor/QueryGrammar/SchemaGrammar](docs/custom-grammers.md)