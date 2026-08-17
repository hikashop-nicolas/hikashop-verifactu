<?php
defined('_JEXEC') or die;

/**
 * Writes to Joomla's log folder, in verifactu.php, under the "verifactu"
 * category.
 *
 * Joomla only writes a category once a logger is registered for it, and that
 * registration has to happen before the first line, hence the check here.
 * The .php extension is deliberate: Joomla starts such a file with a die()
 * line, so the log cannot be read over the web even if the log folder is.
 */
abstract class VerifactuLog
{
    private static bool $registered = false;

    public static function add(string $message, int $level = \Joomla\CMS\Log\Log::INFO): void
    {
        if (!self::$registered) {
            \Joomla\CMS\Log\Log::addLogger(['text_file' => 'verifactu.php'], \Joomla\CMS\Log\Log::ALL, ['verifactu']);
            self::$registered = true;
        }

        \Joomla\CMS\Log\Log::add($message, $level, 'verifactu');
    }
}
