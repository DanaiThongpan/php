<?php
namespace PHPMailer\PHPMailer;

/**
 * PHPMailer Exception Class
 */
class Exception extends \Exception
{
    /**
     * Prettify the error message.
     *
     * @return string
     */
    public function errorMessage()
    {
        return '<strong>' . htmlspecialchars($this->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong><br />\n";
    }
}
