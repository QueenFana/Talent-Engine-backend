<?php
declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $errors = [])
    {
        parent::__construct($message);
    }
}
?>