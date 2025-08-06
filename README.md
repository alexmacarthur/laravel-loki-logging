# Laravel Loki Logging

A package for sending your logs to a [Grafana Loki](https://grafana.com/oss/loki/) server. Set it up, schedule a recurring job, and each log will be batched and sent asynchronously as your application runs.

## Installation and Setup

1. Install the package by running `composer require alexmacarthur/laravel-loki-logging`.
2. Publish the configuration file:

```
php artisan vendor:publish --provider=AlexMacArthur\\LaravelLokiLogging\\L3ServiceProvider
```

3. Create a new log channel in your `config/logging.php` file:

```php
   'loki' => [
     'driver' => 'monolog',
     'handler' => \AlexMacArthur\LaravelLokiLogging\L3Logger::class,
   ]
```

4. Configure at least a `LOG_CHANNEL` environment variable to use the channel you created in the previous step. [See more available environment variables](#environment-variables) below.

5. Configure the `loki:persist` job to run at a regular interval. Unless there's reason to do otherwise, every minute is a good start.

```php
Schedule::command('loki:persist')->everyMinute()->withoutOverlapping();

// Or using the class directly...

use AlexMacArthur\LaravelLokiLogging\L3Persister;

Schedule::command(L3Persister::class)->everyMinute()->withoutOverlapping();
```

6. `Log::info('Start logging!');`

## Environment Variables

By default, the following environment variables are used for logging.

| Name           | Description                                                                                                                                                           |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `LOG_CHANNEL`  | Required. Must be set to 'loki', or whatever you named the channel added to your `logging.php` configuration.                                                         |
| `LOG_SERVER`   | Required. The Loki server to which logs are sent.                                                                                                                     |
| `LOG_USERNAME` | Optional. The username for basic authentication.                                                                                                                      |
| `LOG_PASSWORD` | Optional. The password for basic authentication.                                                                                                                      |
| `LOG_APP`      | Optional. Used for the `application` label on every log. Falls back to `APP_NAME`.                                                                                    |
| `LOG_FORMAT`   | Optional. The format used for each log message. The `level_name` and `message` variables can be used to build the format. By default, it's `[{level_name}] {message}` |

## Configuration

The following configuration properties are used when forming and sending logs:

| Key             | Description                                                                                                                                                                                                                                                                                                                                                                                 |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `context`       | Values to be assigned as labels in the message, used to organize and index logs by Grafana. [Read more about labels here](https://grafana.com/docs/loki/latest/get-started/labels/). Defaults are set at a configuration level, but individual values can be overridden by using the second parameter of Laravel's logging interface: `Log::info('my log', ['application' => 'override']);` |
| `format`        | How log messages should be formatted. [Variable substitutions](#variable-substitution) are available.                                                                                                                                                                                                                                                                                       |
| `loki.server`   | The Loki server where data should be sent.                                                                                                                                                                                                                                                                                                                                                  |
| `loki.username` | Username for HTTP basic authentication. Defaults to empty.                                                                                                                                                                                                                                                                                                                                  |
| `loki.password` | Password for HTTP basic authentication. Defaults to empty.                                                                                                                                                                                                                                                                                                                                  |

## Variable Substitution

All tags and log messages can be enhanced with variable names [provided by Monolog](https://github.com/Seldaek/monolog/blob/main/src/Monolog/LogRecord.php#L106):

- message
- context
- level
- level_name
- channel
- datetime
- extra

To use them, wrap them in curly braces:

```php
'format' => 'My log level is: {level_name}, and my message is: {message}',
```

## Authentication

[Loki does not provide any authentication](https://grafana.com/docs/loki/latest/operations/authentication/) out of the box, but it's highly recommended to configure via reverse proxy. This package only supports Basic Auth. If you place your server behind nginx, this can be [set up here](https://docs.nginx.com/nginx/admin-guide/security-controls/configuring-http-basic-authentication).

## Shout-Out

This package was originally forked from @devcake-deventer's [laravel-loki-logging](https://github.com/devcake-deventer/laravel-loki-logging) package. Thank you for the great starting point!
