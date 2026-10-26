<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * An error response of the WPS API (thrown by helpers, turned into {errors: [...]} by WpsApi).
 */
final class WpsApiError extends RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
