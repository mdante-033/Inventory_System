<?php
// includes/logger.php
// Single, safe Logger implementation. Guarded with class_exists to avoid redeclaration.

if (!class_exists('Logger')) {
	class Logger
	{
		/**
		 * Low-level write function used by all helpers.
		 */
		private static function write(string $level, string $message, array $context = []): void
		{
			$ts = date('Y-m-d H:i:s');
			$ctx = $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
			$line = "[$ts] [$level] $message" . ($ctx !== '' ? " $ctx" : '');

			// Primary: PHP error log for immediate visibility
			error_log($line);

			// Secondary: append to logs/app.log if possible
			$logDir = __DIR__ . '/../logs';
			$logFile = $logDir . '/app.log';

			if (!is_dir($logDir)) {
				@mkdir($logDir, 0755, true);
			}

			if (is_writable($logDir) || (!file_exists($logFile) && is_writable(dirname($logFile)))) {
				@file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
			}
		}

		public static function log(string $level, string $message, array $context = []): void
		{
			self::write(strtoupper($level), $message, $context);
		}

		public static function info(string $message, array $context = []): void
		{
			self::write('INFO', $message, $context);
		}

		public static function warn(string $message, array $context = []): void
		{
			self::write('WARN', $message, $context);
		}

		public static function error(string $message, array $context = []): void
		{
			self::write('ERROR', $message, $context);
		}
	}
}

